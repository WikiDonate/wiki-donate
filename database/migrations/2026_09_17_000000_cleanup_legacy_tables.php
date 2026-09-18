<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['ngo_causes', 'causes', 'ngos', 'payment_logs', 'payments', 'donates', 'talks', 'talk_revisions'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }

        // Clean up legacy columns from existing donations table
        if (Schema::hasTable('donations')) {
            Schema::table('donations', function (Blueprint $table) {
                if (Schema::hasColumn('donations', 'cause_id')) {
                    $table->dropForeign(['cause_id']);
                    $table->dropColumn('cause_id');
                }
            });
        }
    }

    public function down(): void {}
};
