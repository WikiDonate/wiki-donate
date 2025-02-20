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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->nullable();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('edit_talk_page')->default(0); // Stores 0 or 1
            $table->tinyInteger('edit_user_page')->default(0);
            $table->tinyInteger('page_review')->default(0);
            $table->tinyInteger('email_from_other')->default(0);
            $table->tinyInteger('successful_mention')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};