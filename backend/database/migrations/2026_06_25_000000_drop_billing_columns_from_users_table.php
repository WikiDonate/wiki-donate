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
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->dropColumn(['address', 'city', 'state', 'country', 'zip_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $blueprint) {
            $blueprint->string('address')->nullable();
            $blueprint->string('city')->nullable();
            $blueprint->string('state')->nullable();
            $blueprint->string('country')->nullable();
            $blueprint->string('zip_code')->nullable();
        });
    }
};
