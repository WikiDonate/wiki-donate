<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry of pluggable payment methods.
     *
     * Credentials are NEVER stored here — secrets stay in .env and are exposed
     * through config('services.*'). The `config` JSON column only carries
     * non-secret metadata (webhook route/events + the NAME of the env var
     * holding the webhook secret, supported currencies, mode, min_amount).
     *
     * New methods (bKash, ...) are added by inserting a row and deploying the
     * matching provider class; flipping `active` is what surfaces them in the
     * donor/admin UI.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('label', 100);
            // where the method can be used: donor checkout, payouts, or both
            $table->enum('type', ['income', 'payout', 'both'])->default('income');
            // inactive methods stay persisted (in-flight webhooks still work)
            // but are hidden from donor/admin pickers.
            $table->boolean('active')->default(false)->index();
            // NON-SECRET metadata only — never credentials.
            $table->json('config')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });

        $now = now();

        DB::table('payment_methods')->insert([
            [
                'code' => 'paypal',
                'label' => 'PayPal',
                'type' => 'both',
                'active' => true,
                'config' => json_encode([
                    'webhook' => [
                        'route' => 'webhooks/paypal',
                        'events' => ['PAYMENT.CAPTURE.COMPLETED', 'PAYOUTS.BATCH.*', 'PAYOUTS.ITEM.*'],
                    ],
                    'currencies' => ['USD', 'EUR', 'GBP', 'AUD', 'CAD'],
                    // NB: resolved through config() (not env()) so the value is
                    // correct when config is cached; env() only returns the real
                    // value in a non-cached boot.
                    // DB is the source of truth for the gateway mode; .env no
                    // longer carries PAYPAL_MODE. Flip this row to 'live' when
                    // the live PayPal app is ready (see PaymentMethod::mode()).
                    'mode' => 'sandbox',
                    'min_amount' => 1,
                ]),
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'stripe',
                'label' => 'Stripe',
                'type' => 'income',
                // preserved but hidden until the provider is rolled out
                'active' => false,
                'config' => json_encode([
                    'webhook' => [
                        'route' => 'webhooks/stripe',
                        'events' => ['checkout.session.completed', 'payment_intent.succeeded'],
                    ],
                    'currencies' => ['USD', 'EUR', 'GBP'],
                    'mode' => 'test',
                    'min_amount' => 1,
                ]),
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
