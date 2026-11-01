<?php

namespace App\Console\Commands;

use App\Rules\SupportedCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report completed donations stored in a currency the platform no longer
 * accepts.
 *
 * The platform is USD-only (config/donations.php). Balances deliberately
 * EXCLUDE non-USD rows instead of counting them as USD (which would inflate
 * the owed amount and overpay). This command is the audit trail for those
 * rows: it is read-only and exists so an operator can decide, per row,
 * whether to refund, re-record or manually reconcile.
 */
class DonationsLegacyCurrency extends Command
{
    protected $signature = 'donations:legacy-currency
        {--json : Output the report as JSON}';

    protected $description = 'Report completed donations in a non-supported (legacy) currency';

    public function handle(): int
    {
        $supported = SupportedCurrency::code();

        $rows = DB::table('donations')
            ->where('status', 'completed')
            ->whereRaw('UPPER(currency) <> ?', [$supported])
            ->orderBy('id')
            ->get([
                'id',
                'donation_formula_id',
                'amount',
                'currency',
                'created_at',
            ]);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'supported_currency' => $supported,
                'count' => $rows->count(),
                'total' => round((float) $rows->sum('amount'), 2),
                'donations' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info("No completed donations outside {$supported}.");

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d completed donation(s) are in a legacy currency and are EXCLUDED from %s balances.',
            $rows->count(),
            $supported
        ));

        $this->table(
            ['ID', 'Formula', 'Amount', 'Currency', 'Created'],
            $rows->map(fn ($row) => [
                $row->id,
                $row->donation_formula_id,
                number_format((float) $row->amount, 2, '.', ''),
                strtoupper((string) $row->currency),
                (string) $row->created_at,
            ])->all()
        );

        $totals = $rows
            ->groupBy(fn ($row) => strtoupper((string) $row->currency))
            ->map(fn ($group, $currency) => sprintf(
                '%s: %d row(s), %s',
                $currency,
                $group->count(),
                number_format((float) $group->sum('amount'), 2, '.', '')
            ))
            ->values()
            ->all();

        foreach ($totals as $line) {
            $this->line('  '.$line);
        }

        return self::SUCCESS;
    }
}
