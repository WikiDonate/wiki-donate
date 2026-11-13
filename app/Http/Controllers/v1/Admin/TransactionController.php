<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Rules\SupportedCurrency;
use App\Services\Payout\OrganizationPayoutService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin Transaction Log — merged chronological feed of income (completed
 * donations) and expense (organization payouts) rows.
 *
 * View-only: no new money model. Expense rows come from the payout ledger
 * owned by the payouts feature; income rows come from the donations table.
 */
class TransactionController extends Controller
{
    public function __construct(private OrganizationPayoutService $payouts) {}

    /**
     * Paginated merged chronological feed.
     *
     * GET /admin/transactions
     * Filters: from, to, type (income|expense), method (stripe|paypal|bank|other), org, sort (asc|desc)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            [$income, $expense] = $this->collectRows($request);
            $type = $request->input('type');
            $direction = $this->sortDirection($request);
            $rows = $this->mergeRows($income, $expense, $direction);

            if ($type === 'income' || $type === 'expense') {
                $rows = $rows->filter(fn ($r) => $r['type'] === $type)->values();
            }

            $perPage = $this->perPage($request);
            $page = max((int) $request->input('page', 1), 1);
            $total = $rows->count();
            $paged = $rows->forPage($page, $perPage)->values();

            return response()->json([
                'success' => true,
                'message' => 'Transactions retrieved successfully',
                'data' => $paged,
                'meta' => [
                    'currentPage' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'lastPage' => (int) max(ceil($total / $perPage), 1),
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transactions.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Summary figures for the header cards.
     *
     * GET /admin/transactions/summary
     * totalIncome — sum of completed donations
     * totalPayouts (expense) — sum of payouts
     * remainingPayable — owed − paid (live-balance calc from current formula JSON)
     * netInHand — income − payouts
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            // USD-only, like every other money aggregate: counting a legacy
            // EUR/GBP donation as dollars would inflate income and payable.
            $totalIncome = (float) $this->applyDateFilter($request, Donation::query())
                ->where('status', 'completed')
                ->whereRaw('UPPER(currency) = ?', [SupportedCurrency::code()])
                ->sum('amount');

            // Payouts that actually moved or reserved money — the SAME rule the
            // balance math uses (`ON_BALANCE_STATUSES`, reversed rows excluded).
            // A failed row never left the balance; a reversed row came back, so
            // neither may reduce the payable figure or the net-in-hand.
            $totalPayouts = (float) $this->onBalancePayouts($request)->sum('amount');

            // Provider-reported transaction-cost totals for the same filter
            // window. Gross is `amount`; the organization received `net_amount`
            // after PayPal's actual (receiver-paid) fee. Pending rows have no
            // fee yet (NULL) until the provider confirms.
            $feeTotals = $this->onBalancePayouts($request)
                ->selectRaw('COALESCE(SUM(fee_amount), 0) as total_fee, COALESCE(SUM(net_amount), 0) as total_net')
                ->first();

            // Owed comes from the payout service so this figure can never drift
            // from the balance the payouts feature pays out of (same USD-only,
            // same per-allocation rounding). Still window-scoped by the date
            // filter for report consistency.
            $totalOwed = $this->payouts->totalOwed(function ($donations) use ($request) {
                $donations
                    ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
                    ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')));
            });

            return response()->json([
                'success' => true,
                'message' => 'Transaction summary retrieved successfully',
                'data' => [
                    'totalIncome' => round($totalIncome, 2),
                    'totalPayouts' => round($totalPayouts, 2),
                    // Provider fee charged on payouts in this window.
                    'totalPayoutFees' => round((float) ($feeTotals->total_fee ?? 0), 2),
                    // What organizations actually received (gross - fee).
                    'totalPayoutNet' => round((float) ($feeTotals->total_net ?? 0), 2),
                    // Display-only estimate; actual fees are provider-reported
                    // and already summed in totalPayoutFees.
                    'payoutFeeEstimatePercent' => (float) config('donations.payout_fee_estimate_percent', 2),
                    'remainingPayable' => round(max($totalOwed - $totalPayouts, 0), 2),
                    // Income kept after the actual (net) transfers plus the
                    // withheld fee stays with the platform, so net-in-hand is
                    // still income - gross payouts (fee is not paid out).
                    'netInHand' => round($totalIncome - $totalPayouts, 2),
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transaction summary.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * CSV export of the filtered view.
     *
     * GET /admin/transactions/export
     */
    public function export(Request $request): StreamedResponse
    {
        [$income, $expense] = $this->collectRows($request);
        $type = $request->input('type');
        $rows = $this->mergeRows($income, $expense, $this->sortDirection($request));

        if ($type === 'income' || $type === 'expense') {
            $rows = $rows->filter(fn ($r) => $r['type'] === $type)->values();
        }

        $filename = 'transactions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, [
                'Date', 'Type', 'Description', 'Amount', 'Fee', 'Net', 'Fee %', 'Currency', 'Method', 'Status', 'Context',
            ]);

            foreach ($rows as $row) {
                $isExpense = $row['type'] === 'expense';
                fputcsv($out, [
                    $row['date_raw'],
                    $row['type'],
                    $row['description'],
                    $isExpense ? '-'.number_format($row['amount'], 2, '.', '') : number_format($row['amount'], 2, '.', ''),
                    // Fee/net only exist on payout (expense) rows; the fee is
                    // shown as a negative cash-out line so column math works.
                    $isExpense && $row['fee_amount'] !== null
                        ? '-'.number_format((float) $row['fee_amount'], 2, '.', '')
                        : '',
                    $isExpense && $row['net_amount'] !== null
                        ? '-'.number_format((float) $row['net_amount'], 2, '.', '')
                        : '',
                    $isExpense && $row['fee_percent'] !== null ? $row['fee_percent'] : '',
                    $row['currency'],
                    $row['method'] ?? '',
                    $row['status'] ?? '',
                    $row['context'] ?? '',
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 15), 1), 100);
    }

    /**
     * Base query for payouts that count against the balance — the SAME rule the
     * payout service uses: only `paid` + `pending` states, and never a row that
     * was reversed (that money came back). Failed rows are excluded by the
     * status list. Applies the shared method + date filters so every payout
     * aggregate and the expense feed window identically.
     *
     * Date filtering mirrors collectRows(): rows with a paid_at are bounded by
     * it, rows still awaiting confirmation fall back to created_at.
     */
    private function onBalancePayouts(Request $request)
    {
        return OrganizationPayout::query()
            ->whereIn('status', OrganizationPayout::ON_BALANCE_STATUSES)
            ->whereNull('reversed_at')
            ->when($request->filled('method'), fn ($q) => $q->where('payment_method', $request->input('method')))
            ->when($request->filled('from'), fn ($q) => $q->where(function ($qq) use ($request) {
                $qq->whereDate('paid_at', '>=', $request->input('from'))
                    ->orWhere(fn ($qqq) => $qqq->whereNull('paid_at')->whereDate('created_at', '>=', $request->input('from')));
            }))
            ->when($request->filled('to'), fn ($q) => $q->where(function ($qq) use ($request) {
                $qq->whereDate('paid_at', '<=', $request->input('to'))
                    ->orWhere(fn ($qqq) => $qqq->whereNull('paid_at')->whereDate('created_at', '<=', $request->input('to')));
            }));
    }

    private function sortDirection(Request $request): string
    {
        return strtolower($request->input('sort', 'desc')) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * Collect filtered income and expense rows as plain arrays keyed by sort date.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function collectRows(Request $request): array
    {
        $org = trim((string) $request->input('org', ''));

        // ---- Income rows: completed donations --------------------------------
        $methodFilter = trim((string) $request->input('method', ''));
        $income = (clone $this->applyDateFilter($request, Donation::query()))
            ->where('status', 'completed')
            ->with('formula.article:id,slug,title')
            ->get()
            ->filter(function (Donation $d) use ($org, $methodFilter) {
                $formula = $d->formula;

                if ($org !== '' && ! $this->formulaHasOrg($formula, $org)) {
                    return false;
                }
                if ($methodFilter !== '') {
                    $incomeMethod = $d->paymentMethodCode() ?? 'unknown';
                    if (strcasecmp($incomeMethod, $methodFilter) !== 0) {
                        return false;
                    }
                }

                return true;
            })
            ->map(fn (Donation $d) => $this->incomeRow($d))
            ->values();

        // ---- Expense rows: organization payouts -------------------------------
        // Only payouts that moved or reserved money appear (same rule as the
        // balance math): failed rows never left the balance and reversed rows
        // came back, so neither is a real cash-out. Method + date filters are
        // applied by onBalancePayouts().
        $expense = $this->onBalancePayouts($request)
            ->with('actorUser:id,username')
            ->get()
            ->filter(function ($payout) use ($org) {
                if ($org !== '' && strcasecmp($payout->organization_name, $org) !== 0) {
                    return false;
                }

                return true;
            })
            ->map(fn ($payout) => $this->expenseRow($payout))
            ->values();

        return [$income, $expense];
    }

    private function incomeRow(Donation $d): array
    {
        $formula = $d->formula;
        $article = $formula?->article;
        $method = $d->paymentMethodCode() ?? 'unknown';

        $description = 'Donation from '.($d->donor_name ?: $d->user?->username ?: ($d->donor_email ?: 'Guest'));

        return [
            'id' => 'donation-'.$d->id,
            'type' => 'income',
            'date' => $d->created_at->format('d M, Y'),
            'date_raw' => $d->created_at->toISOString(),
            'sort_date' => $d->created_at->timestamp,
            'description' => $description,
            'donor_name' => $d->donor_name ?: $d->user?->username,
            'donor_email' => $d->donor_email ?? $d->user?->email,
            'amount' => (float) $d->amount,
            'currency' => $d->currency,
            'method' => $method,
            'status' => $d->status,
            'article' => $article ? ['slug' => $article->slug, 'title' => $article->title] : null,
            'formula_id' => $formula?->id,
            'context' => $article ? 'Article: '.($article->title ?? $article->slug) : null,
        ];
    }

    private function expenseRow(OrganizationPayout $payout): array
    {
        $paidAt = $payout->paid_at ? Carbon::parse($payout->paid_at) : $payout->created_at;
        $actor = $payout->actorUser?->username;

        return [
            'id' => 'payout-'.$payout->id,
            'type' => 'expense',
            'date' => $paidAt->format('d M, Y'),
            'date_raw' => $paidAt->toISOString(),
            'sort_date' => $paidAt->timestamp,
            'description' => 'Payout to '.$payout->organization_name,
            'organization_name' => $payout->organization_name,
            'amount' => (float) $payout->amount,
            // Enforced transaction-cost breakdown. `amount` is gross (what
            // consumes the owed balance); `net_amount` is what was sent.
            'fee_percent' => $payout->fee_percent === null ? null : (float) $payout->fee_percent,
            'fee_amount' => $payout->fee_amount === null ? null : (float) $payout->fee_amount,
            'net_amount' => $payout->net_amount === null ? null : (float) $payout->net_amount,
            'currency' => $payout->currency,
            'method' => $payout->payment_method,
            'status' => $payout->status,
            'payout_type' => $payout->type,
            'note' => $payout->note,
            'actor' => $actor,
            'formula_id' => $payout->donation_formula_id,
            'context' => 'Payout '.($payout->type === 'full' ? '(full)' : '(partial)').($actor ? ' by '.$actor : ''),
        ];
    }

    /**
     * @param  Collection<int, array>  $income
     * @param  Collection<int, array>  $expense
     * @return Collection<int, array>
     */
    private function mergeRows(Collection $income, Collection $expense, string $direction): Collection
    {
        return $income
            ->concat($expense)
            ->sort(function ($a, $b) use ($direction) {
                if ($a['sort_date'] === $b['sort_date']) {
                    return $direction === 'desc'
                        ? strcasecmp($b['id'], $a['id'])
                        : strcasecmp($a['id'], $b['id']);
                }

                return $direction === 'desc'
                    ? $b['sort_date'] <=> $a['sort_date']
                    : $a['sort_date'] <=> $b['sort_date'];
            })
            ->values();
    }

    private function applyDateFilter(Request $request, $q)
    {
        return $q
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->input('to')));
    }

    private function formulaHasOrg(?DonationFormula $formula, string $org): bool
    {
        if (! $formula || ! is_array($formula->formula)) {
            return false;
        }

        return collect($formula->formula)->contains(
            fn ($item) => strcasecmp(trim((string) ($item['organization'] ?? '')), $org) === 0
        );
    }
}
