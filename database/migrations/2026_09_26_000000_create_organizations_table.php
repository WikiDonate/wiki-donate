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
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('ein')->nullable()->unique();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable()->default('US');
            $table->string('paypal_email')->nullable();
            $table->string('payout_status')->default('unverified');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Fresh installs create organization_payouts (2026_09_15) before this
        // table exists, so the registry FK is deferred until now. Wrap in
        // try/catch to stay idempotent on databases that already have it.
        if (Schema::hasTable('organization_payouts') && Schema::hasColumn('organization_payouts', 'organization_id')) {
            try {
                Schema::table('organization_payouts', function (Blueprint $table) {
                    $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
                });
            } catch (Throwable $e) {
                // FK already exists — safe to ignore.
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
