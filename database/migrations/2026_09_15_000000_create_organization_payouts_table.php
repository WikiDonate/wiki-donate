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
        Schema::create('organization_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('donation_formula_id')->constrained('donation_formulas')->onDelete('cascade');
            $table->string('organization_name');
            $table->string('organization_key');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('usd');
            $table->enum('type', ['full', 'partial']);
            $table->enum('status', ['paid'])->default('paid');
            $table->timestamp('paid_at');
            $table->foreignId('actor_id')->constrained('users')->onDelete('cascade');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['donation_formula_id', 'organization_key']);
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
