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
        // Drop the existing unique index on slug
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique('articles_slug_unique');
        });

        // Add unique index on slug + type combination
        Schema::table('articles', function (Blueprint $table) {
            $table->unique(['slug', 'type'], 'articles_slug_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the unique index on slug + type
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique('articles_slug_type_unique');
        });

        // Restore the unique index on slug
        Schema::table('articles', function (Blueprint $table) {
            $table->unique('slug', 'articles_slug_unique');
        });
    }
};
