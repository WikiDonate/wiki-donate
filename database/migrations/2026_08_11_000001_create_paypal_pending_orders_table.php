<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_pending_orders', function (Blueprint $table) {
            $table->id();
            $table->string('paypal_order_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('donation_formula_id')->nullable()->constrained('donation_formulas')->restrictOnDelete();
            $table->string('donor_email')->nullable();
            $table->string('donor_name')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->text('details')->nullable();
            $table->timestamps();

            $table->index('paypal_order_id');
            $table->index('donor_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_pending_orders');
    }
};
