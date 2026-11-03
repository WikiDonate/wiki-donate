<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The platform is USD-only (config/donations.php). Narrow every payment
     * method's advertised `currencies` metadata to the single supported code
     * so the registry and the API validation agree.
     *
     * Only `config.currencies` is touched; mode, webhook and limits are kept.
     */
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('payment_methods')) {
            return;
        }

        $supported = strtoupper((string) config('donations.currency', 'USD'));

        foreach (DB::table('payment_methods')->get() as $row) {
            if (empty($row->config)) {
                continue;
            }

            $config = json_decode($row->config, true);
            if (! is_array($config)) {
                continue;
            }

            $config['currencies'] = [$supported];

            DB::table('payment_methods')->where('id', $row->id)->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Restoring the previous multi-currency list is not meaningful: the
        // application no longer accepts those codes. No-op by design.
    }
};
