<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization payouts ledger (append-only).
 *
 * Owned by the payouts feature; the Admin Transaction Log reads from it for
 * expense rows. Amounts are never edited or deleted — corrections happen via
 * new entries. Balance is always computed live, never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_formula_id')->constrained('donation_formulas')->cascadeOnDelete();
            $table->string('organization_name');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('usd');
            $table->enum('type', ['full', 'partial']);
            $table->string('status')->default('completed');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('actor')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('method')->nullable(); // bank | bKash | other
            $table->timestamps();

            $table->index(['donation_formula_id', 'organization_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_payouts');
    }
};
