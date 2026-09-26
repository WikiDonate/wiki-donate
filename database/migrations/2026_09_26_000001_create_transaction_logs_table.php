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
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('event');
            $table->string('category')->default('organization');
            $table->morphs('subject');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('note')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['event', 'category']);
            $table->index('created_at');
            $table->index('actor_id');
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
