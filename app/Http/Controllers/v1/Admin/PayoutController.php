<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin payouts (v1).
 *
 * Balances are computed LIVE from the CURRENT donation_formulas.formula JSON
 * (Option A — no donation-time snapshots) minus the append-only payouts ledger.
 * Formula edits therefore retroactively change owed amounts; that trade-off is
 * accepted and surfaced in the UI via the "Edited by user" notice.
 *
 * Payout target key = donation_formula_id + organization_name (the org name
 * string inside formula items). Creating a payout is transactional and locks
 * the target rows (FOR UPDATE) to prevent races / overpayment.
 */
class PayoutController extends Controller
{
    /**
     * Payable allocations per (formula_id + organization_name):
     * owed = Σ(completed donations.amount × percentage/100) − Σ(payouts).
     */
    public function allocations(): JsonResponse
    {
        $allocations = collect($this->computeAllocations());

        return response()->json([
            'allocations' => $allocations->values(),
        ]);
    }

    /**
     * Payout history, most recent first.
     */
    public function index(): JsonResponse
    {
        $payouts = OrganizationPayout::with([
            'formula.article:id,slug,title',
            'actorUser:id,uuid,username',
        ])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OrganizationPayout $payout) => $this->payoutRow($payout));

        return response()->json(['payouts' => $payouts]);
    }

    /**
     * Create a payout. Validated and transactional; rejects amount <= 0 or
     * amount > live balance. Type auto-derived: full if it pays the balance
     * in full at payout time, else partial.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'donation_formula_id' => 'required|integer|exists:donation_formulas,id',
            'organization_name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'note' => 'nullable|string|max:2000',
            'method' => 'nullable|string|max:50',
        ]);

        $payout = DB::transaction(function () use ($validated, $request) {
            // Lock ledger rows for this target so concurrent payouts serialize.
            OrganizationPayout::where('donation_formula_id', $validated['donation_formula_id'])
                ->where('organization_name', $validated['organization_name'])
                ->lockForUpdate()
                ->get();

            $allocation = collect($this->computeAllocations())
                ->firstWhere(fn ($a) => $a['donation_formula_id'] === (int) $validated['donation_formula_id']
                    && $a['organization_name'] === $validated['organization_name']);

            if ($allocation === null) {
                abort(response()->json([
                    'message' => 'No allocation found for this formula + organization.',
                ], 422));
            }

            $balance = (float) $allocation['balance'];
            $amount = round((float) $validated['amount'], 2);

            if ($amount <= 0) {
                abort(response()->json([
                    'message' => 'Payout amount must be greater than zero.',
                ], 422));
            }

            if ($amount > $balance + 0.001) {
                abort(response()->json([
                    'message' => 'Amount exceeds the available balance of '
                        .number_format($balance, 2, '.', '')
                        .' '
                        .$allocation['currency']
                        .'.',
                ], 422));
            }

            return OrganizationPayout::create([
                'donation_formula_id' => $allocation['donation_formula_id'],
                'organization_name' => $allocation['organization_name'],
                'amount' => $amount,
                'currency' => $validated['currency'] ?? $allocation['currency'],
                'type' => abs($amount - $balance) < 0.001 ? 'full' : 'partial',
                'status' => 'completed',
                'paid_at' => now(),
                'actor' => $request->user()?->id,
                'note' => $validated['note'] ?? null,
                'method' => $validated['method'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Payout recorded.',
            'payout' => $this->payoutRow($payout->load(['formula.article:id,slug,title', 'actorUser:id,uuid,username'])),
        ], 201);
    }

    /**
     * Compute all live allocations from current formula JSON and the ledger.
     *
     * @return array<int, array{
     *     donation_formula_id: int, formula_uuid: string, organization_name: string,
     *     article: array|null, owed: float, paid: float, balance: float,
     *     currency: string, is_edited: bool
     * }>
     */
    private function computeAllocations(): array
    {
        // Donations grouped per formula+currency.
        $donationSums = Donation::query()
            ->where('status', 'completed')
            ->whereNotNull('donation_formula_id')
            ->groupBy('donation_formula_id', 'currency')
            ->selectRaw('donation_formula_id, currency, SUM(amount) as total')
            ->get()
            ->groupBy('donation_formula_id');

        $payoutSums = OrganizationPayout::query()
            ->groupBy('donation_formula_id', 'organization_name', 'currency')
            ->selectRaw("donation_formula_id, organization_name, currency, SUM(amount) as total")
            ->get();

        $allocations = [];

        foreach ($donationSums as $formulaId => $currencyGroups) {
            $formula = DonationFormula::with('article:id,slug,title')->find($formulaId);
            if (! $formula) {
                continue;
            }

            $items = $formula->formula ?? [];
            if (! is_array($items) || $items === []) {
                continue;
            }

            foreach ($currencyGroups as $row) {
                $currency = $row->currency;
                $owedTotal = (float) $row->total;

                foreach ($items as $item) {
                    $organization = $item['organization'] ?? null;
                    $percentage = (float) ($item['percentage'] ?? 0);
                    if ($organization === null || $percentage <= 0) {
                        continue;
                    }

                    $owed = round($owedTotal * $percentage / 100, 2);

                    $paid = (float) $payoutSums
                        ->firstWhere(fn ($p) => $p->donation_formula_id === (int) $formulaId
                            && $p->organization_name === $organization
                            && $p->currency === $currency)?->total;

                    $paid = round($paid, 2);

                    // Skip never-owed, never-paid allocations to keep the list tight.
                    if ($owed <= 0 && $paid <= 0) {
                        continue;
                    }

                    $allocations[] = [
                        'donation_formula_id' => (int) $formulaId,
                        'formula_uuid' => $formula->uuid,
                        'organization_name' => $organization,
                        'article' => $formula->article ? [
                            'slug' => $formula->article->slug,
                            'title' => $formula->article->title,
                        ] : null,
                        'owed' => $owed,
                        'paid' => $paid,
                        'balance' => round($owed - $paid, 2),
                        'currency' => $currency,
                        'is_edited' => $formula->hasCompletedDonation(),
                    ];
                }
            }
        }

        return $allocations;
    }

    private function payoutRow(OrganizationPayout $payout): array
    {
        $formula = $payout->formula;
        $article = $formula?->article;

        return [
            'id' => $payout->id,
            'donation_formula_id' => $payout->donation_formula_id,
            'formula_uuid' => $formula?->uuid,
            'organization_name' => $payout->organization_name,
            'article' => $article ? ['slug' => $article->slug, 'title' => $article->title] : null,
            'amount' => (float) $payout->amount,
            'currency' => $payout->currency,
            'type' => $payout->type,
            'status' => $payout->status,
            'paid_at' => optional($payout->paid_at)->toIso8601String(),
            'actor' => $payout->actorUser?->username,
            'note' => $payout->note,
            'method' => $payout->method,
        ];
    }
}
