<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->string('paypal_order_id')->nullable()->unique()->after('stripe_payment_intent_id');
            $table->foreignId('donation_formula_id')->nullable()->constrained('donation_formulas')->restrictOnDelete()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropForeign(['donation_formula_id']);
            $table->dropColumn(['paypal_order_id', 'donation_formula_id']);
        });
    }
};
