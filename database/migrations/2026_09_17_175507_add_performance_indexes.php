<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('type');
            $table->index('access_type');
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->index('donation_formula_id');
            $table->index('user_id');
            $table->index('created_at');
        });

        Schema::table('revisions', function (Blueprint $table) {
            $table->index('article_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['type']);
            $table->dropIndex(['access_type']);
        });

        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['donation_formula_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('revisions', function (Blueprint $table) {
            $table->dropIndex(['article_id']);
            $table->dropIndex(['user_id']);
        });
    }
};
