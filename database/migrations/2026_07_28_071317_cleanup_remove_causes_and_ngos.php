<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove cause_id from payments
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'cause_id')) {
                $table->dropForeign(['cause_id']);
                $table->dropColumn('cause_id');
            }
        });

        // Remove cause_id and ngo_id from payment_logs
        Schema::table('payment_logs', function (Blueprint $table) {
            if (Schema::hasColumn('payment_logs', 'cause_id')) {
                $table->dropForeign(['cause_id']);
                $table->dropColumn('cause_id');
            }
            if (Schema::hasColumn('payment_logs', 'ngo_id')) {
                $table->dropForeign(['ngo_id']);
                $table->dropColumn('ngo_id');
            }
        });

        // Remove cause_id from donations
        Schema::table('donations', function (Blueprint $table) {
            if (Schema::hasColumn('donations', 'cause_id')) {
                $table->dropForeign(['cause_id']);
                $table->dropColumn('cause_id');
            }
        });

        // Drop pivot table first, then parent tables
        Schema::dropIfExists('ngo_causes');
        Schema::dropIfExists('causes');
        Schema::dropIfExists('ngos');
    }

    public function down(): void
    {
        // No reverse — these tables/models are permanently removed
    }
};
