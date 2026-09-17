<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->softDeletes()->after('details');
        });

        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->boolean('is_edited')->default(false)->after('deleted_at');
            $table->timestamp('edited_at')->nullable()->after('is_edited');
        });
    }

    public function down(): void
    {
        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->dropColumn(['is_edited', 'edited_at']);
        });

        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
