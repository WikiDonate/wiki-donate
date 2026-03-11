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
        Schema::table('donation_formulas', function (Blueprint $table) {
            // Add a title to distinguish between multiple formulas
            $table->string('title')->nullable()->after('user_id');

            // Add non-unique indexes so we can drop the unique one
            // without violating foreign key index requirements in some MySQL versions
            $table->index('article_id');
            $table->index('user_id');
        });

        Schema::table('donation_formulas', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique(['article_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->dropColumn('title');

            // Re-add the unique constraint
            $table->unique(['article_id', 'user_id']);

            $table->dropIndex(['article_id']);
            $table->dropIndex(['user_id']);
        });
    }
};
