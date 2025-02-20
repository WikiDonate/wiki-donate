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
        Schema::create('donates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('card_number');
            $table->string('expiry_month', 2);
            $table->string('expiry_year', 4);
            $table->string('cvv');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('USD');
            $table->unsignedTinyInteger('status')->default(0)->comment('Payment status: 0 = unpaid, 1 = paid');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donates');
    }
};
