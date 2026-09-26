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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // IRS Employer Identification Number (XX-XXXXXXX). Stable dedupe
            // key — names change, merge and collide; EINs don't. Nullable
            // because free-text rows may reference small/unregistered orgs.
            $table->string('ein', 20)->nullable()->unique();
            $table->string('city', 100)->nullable();
            $table->string('state', 10)->nullable();
            // Receiving endpoint for PayPal Payouts. Collected and confirmed
            // once per org by an admin before the first payout.
            $table->string('paypal_email')->nullable();
            $table->string('payout_status', 20)->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
