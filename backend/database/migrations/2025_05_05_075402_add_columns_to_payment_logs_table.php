<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsToPaymentLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            // Add user_id foreign key if not exists
            if (! Schema::hasColumn('payment_logs', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            }

            // Add currency column if not exists
            if (! Schema::hasColumn('payment_logs', 'currency')) {
                $table->string('currency', 3)->default('USD');
            }

            // Add PayPal related columns if not exists
            if (! Schema::hasColumn('payment_logs', 'paypal_payout_id')) {
                $table->string('paypal_payout_id')->nullable()->after('currency');
            }
            if (! Schema::hasColumn('payment_logs', 'paypal_status')) {
                $table->string('paypal_status')->nullable()->after('paypal_payout_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id',
                'currency',
                'paypal_payout_id',
                'paypal_status',
            ]);
        });
    }
}
