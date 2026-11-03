<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable log of every inbound payment webhook delivery.
     *
     * Why this exists: idempotency used to live in the file cache
     * (`Cache::store('file')->has("paypal_webhook:{id}")`). A deploy clears
     * that cache, so a PayPal retransmission after a release was processed a
     * second time. The unique (provider, event_id) key below is now the
     * dedupe lock, and it doubles as the incident trail ("did we ever receive
     * event X?") that the log file can't answer.
     *
     * Rows are written BEFORE signature verification on purpose: a forged or
     * replayed request must leave a trace as well.
     */
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // payment_methods.code, e.g. paypal | stripe
            $table->string('provider', 30);
            // Gateway event id. Falls back to a payload hash when the gateway
            // omits one, so every delivery is still deduplicated.
            $table->string('event_id', 100);
            $table->string('event_type', 100)->nullable();

            // received | processed | failed | ignored
            $table->string('status', 20)->default('received');

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('signature_valid')->nullable();

            // Raw body (decoded) + a filtered header subset. Never the full
            // header bag: it carries cookies/auth headers for other paths.
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();

            // Best-effort link to the row the event affected
            // (Donation | OrganizationPayout | ...). Null when unresolved.
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            // Sanitized reason only — never a raw provider message body.
            $table->text('error_message')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index('event_type');
            $table->index('status');
            $table->index('received_at');
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
