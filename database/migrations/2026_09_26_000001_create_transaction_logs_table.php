<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Report-friendly audit trail for every transaction-relevant action:
     * org registry upserts, PayPal email changes, verification changes and
     * payouts. Rows are append-only; admins can filter by event / actor /
     * subject and export later.
     */
    public function up(): void
    {
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // machine-readable event name, e.g. organization.verified,
            // organization.paypal_email_changed, payout.created, payout.blocked
            $table->string('event', 100)->index();
            // acting user (null for system/webhook events)
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // role the actor held at the time (Admin|Editor|Webhook|System)
            $table->string('actor_role', 30)->nullable();
            // polymorphic subject (Organization, OrganizationPayout, DonationFormula, ...)
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // human-readable summary line, pre-rendered for quick filtering
            $table->string('message', 500)->nullable();
            // structured before/after values for the changed fields
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_logs');
    }
};
