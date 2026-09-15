<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationFormula;
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
    /**
     * Paginated merged chronological feed.
     *
     * GET /admin/transactions
     * Filters: from, to, type (income|expense), method (stripe|paypal|bank|bKash|other), org, article, sort (asc|desc)
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
                'errors' => [$e->getMessage()],
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
            $totalIncome = (float) $this->applyDateFilter($request, Donation::query())
                ->where('status', 'completed')
                ->sum('amount');

            $totalPayouts = (float) \App\Models\OrganizationPayout::query()
                ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_at', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_at', '<=', $request->input('to')))
                ->when($request->filled('method'), fn ($q) => $q->where('type', $request->input('method')))
                ->when($request->filled('org'), fn ($q) => $q->where('organization_name', 'like', '%'.$request->input('org').'%'))
                ->sum('amount');

            [$totalOwed] = $this->computeOwed($request);

            return response()->json([
                'success' => true,
                'message' => 'Transaction summary retrieved successfully',
                'data' => [
                    'totalIncome' => round($totalIncome, 2),
                    'totalPayouts' => round($totalPayouts, 2),
                    'remainingPayable' => round(max($totalOwed - $totalPayouts, 0), 2),
                    'netInHand' => round($totalIncome - $totalPayouts, 2),
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transaction summary.',
                'errors' => [$e->getMessage()],
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
            fputcsv($out, ['Date', 'Type', 'Description', 'Amount', 'Currency', 'Method', 'Status', 'Context']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['date_raw'],
                    $row['type'],
                    $row['description'],
                    $row['type'] === 'expense' ? '-'.number_format($row['amount'], 2, '.', '') : number_format($row['amount'], 2, '.', ''),
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
        return min(max((int) $request->input('per_page', 20), 1), 100);
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
        $articleSlug = trim((string) $request->input('article', ''));

        // ---- Income rows: completed donations --------------------------------
        $income = (clone $this->applyDateFilter($request, Donation::query()))
            ->where('status', 'completed')
            ->with('formula.article:id,slug,title')
            ->get()
            ->filter(function (Donation $d) use ($org, $articleSlug) {
                $formula = $d->formula;
                $article = $formula?->article;

                if ($org !== '' && ! $this->formulaHasOrg($formula, $org)) {
                    return false;
                }
                if ($articleSlug !== '' && (! $article || $article->slug !== $articleSlug)) {
                    return false;
                }

                return true;
            })
            ->map(fn (Donation $d) => $this->incomeRow($d))
            ->values();

        // ---- Expense rows: organization payouts -------------------------------
        $expense = \App\Models\OrganizationPayout::query()
            ->when($request->filled('method'), fn ($q) => $q->where('type', $request->input('method')))
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
        $method = $d->paypal_order_id ? 'paypal' : 'stripe';

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

    private function expenseRow($payout): array
    {
        $paidAt = $payout->paid_at ? Carbon::parse($payout->paid_at) : $payout->created_at;
        $actor = $payout->actor ? \App\Models\User::find($payout->actor)?->username : null;

        return [
            'id' => 'payout-'.$payout->id,
            'type' => 'expense',
            'date' => $paidAt->format('d M, Y'),
            'date_raw' => $paidAt->toISOString(),
            'sort_date' => $paidAt->timestamp,
            'description' => 'Payout to '.$payout->organization_name,
            'organization_name' => $payout->organization_name,
            'amount' => (float) $payout->amount,
            'currency' => $payout->currency,
            'method' => $payout->note ?? null,
            'status' => $payout->status,
            'payout_type' => $payout->type,
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
            ->merge($expense)
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

    /**
     * Live owed computation across current formula JSON (Option A): for every
     * (formula_id + organization name) allocation, owed = Σ(completed donation
     * amount × percentage/100). Paid is summed from the payout ledger.
     *
     * @return array{0: float, 1: array<int, array{formula_id:int, organization:string, owed:float, paid:float, balance:float}>}
     */
    private function computeOwed(Request $request): array
    {
        $allocs = [];

        $donations = (clone $this->applyDateFilter($request, Donation::query()))
            ->where('status', 'completed')
            ->with('formula:id,formula')
            ->get(['id', 'amount', 'donation_formula_id']);

        foreach ($donations as $donation) {
            $items = $donation->formula?->formula;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $name = trim((string) ($item['organization'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $pct = (float) ($item['percentage'] ?? 0);
                $allocs[$donation->donation_formula_id.'|'.$name] ??= [
                    'formula_id' => $donation->donation_formula_id,
                    'organization' => $name,
                    'owed' => 0.0,
                ];
                $allocs[$donation->donation_formula_id.'|'.$name]['owed'] += (float) $donation->amount * $pct / 100;
            }
        }

        $paidBy = \App\Models\OrganizationPayout::query()
            ->get()
            ->groupBy(fn ($p) => $p->donation_formula_id.'|'.$p->organization_name);

        $totalOwed = 0.0;
        $detail = collect($allocs)
            ->map(function ($alloc) use ($paidBy, &$totalOwed) {
                $key = $alloc['formula_id'].'|'.$alloc['organization'];
                $paid = (float) ($paidBy->get($key)?->sum('amount') ?? 0);
                $totalOwed += $alloc['owed'];

                return [
                    'formula_id' => $alloc['formula_id'],
                    'organization' => $alloc['organization'],
                    'owed' => round($alloc['owed'], 2),
                    'paid' => round($paid, 2),
                    'balance' => round($alloc['owed'] - $paid, 2),
                ];
            })
            ->values()
            ->all();

        return [$totalOwed, $detail];
    }
}
