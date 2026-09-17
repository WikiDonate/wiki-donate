<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organization_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donation_formula_id')->constrained('donation_formulas');
            $table->string('organization_name');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->string('type')->nullable()->comment('full|partial');
            $table->string('status')->nullable()->comment('completed|pending|failed');
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('actor')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->string('method')->nullable();
            $table->timestamps();

            $table->index(['donation_formula_id', 'organization_name']);
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
