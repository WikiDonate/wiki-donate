<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Correct the PayPal webhook event names stored in the registry.
     *
     * The original seed used `PAYOUTS.BATCH.*` / `PAYOUTS.ITEM.*`, which are
     * not the names PayPal's Payouts product emits — the real family is
     * `PAYMENT.PAYOUTSBATCH.*` / `PAYMENT.PAYOUTS-ITEM.*`. Any deployment that
     * configured the PayPal dashboard from this metadata was subscribing to
     * nothing, so payouts never reconciled asynchronously.
     *
     * Only the paypal row's config.webhook.events is touched; everything else
     * (mode, currencies, limits) is preserved.
     */
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('payment_methods')) {
            return;
        }

        $row = DB::table('payment_methods')->where('code', 'paypal')->first();
        if (! $row || empty($row->config)) {
            return;
        }

        $config = json_decode($row->config, true);
        if (! is_array($config)) {
            return;
        }

        $config['webhook']['events'] = [
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.PAYOUTSBATCH.SUCCESS',
            'PAYMENT.PAYOUTSBATCH.DENIED',
            'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
            'PAYMENT.PAYOUTS-ITEM.FAILED',
            'PAYMENT.PAYOUTS-ITEM.RETURNED',
            'PAYMENT.PAYOUTS-ITEM.BLOCKED',
            'PAYMENT.PAYOUTS-ITEM.CANCELED',
            'PAYMENT.PAYOUTS-ITEM.UNCLAIMED',
        ];

        DB::table('payment_methods')->where('code', 'paypal')->update([
            'config' => json_encode($config),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('payment_methods')) {
            return;
        }

        $row = DB::table('payment_methods')->where('code', 'paypal')->first();
        if (! $row || empty($row->config)) {
            return;
        }

        $config = json_decode($row->config, true);
        if (! is_array($config)) {
            return;
        }

        $config['webhook']['events'] = ['PAYMENT.CAPTURE.COMPLETED', 'PAYOUTS.BATCH.*', 'PAYOUTS.ITEM.*'];

        DB::table('payment_methods')->where('code', 'paypal')->update([
            'config' => json_encode($config),
            'updated_at' => now(),
        ]);
    }
};
