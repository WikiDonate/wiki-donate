<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only payout ledger. Amounts are never edited or deleted;
     * balances are always computed live from this ledger.
     */
    public function up(): void
    {
        if (Schema::hasTable('organization_payouts')) {
            Schema::drop('organization_payouts');
        }

        Schema::create('organization_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('donation_formula_id')->constrained('donation_formulas')->onDelete('cascade');
            $table->string('organization_name');
            $table->string('organization_key');
            // Resolved registry entry for this allocation (nullable for
            // legacy rows created before the registry existed).
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            // Frozen destination so history stays readable even if the org's
            // PayPal email changes later. Ledger remains append-only.
            $table->string('destination_paypal_email')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('usd');
            $table->string('type')->nullable()->comment('full|partial');
            // paid | pending (transfer submitted to PayPal) | failed.
            // Ledger stays append-only: failed rows are excluded from
            // balance math so an admin can retry.
            $table->string('status')->nullable()->default('pending');
            // Null until the PayPal transfer actually completes.
            $table->timestamp('paid_at')->nullable();
            // Provider (PayPal Payouts) tracking fields.
            $table->string('payout_batch_id')->nullable();
            $table->string('payout_item_id')->nullable();
            $table->string('provider_status')->nullable()->comment('PayPal item transaction_status');
            $table->text('failure_reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->onDelete('cascade');
            $table->string('method')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['donation_formula_id', 'organization_key']);
            $table->index('payout_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_payouts');
    }
};
