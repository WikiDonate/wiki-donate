<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Token-based PayPal email confirmation: when an admin sets or changes
     * an organization's receiving email, a confirmation link is mailed to
     * that address. Only a click on the link proves the address exists and
     * is controlled by someone who reads it.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'paypal_confirm_token')) {
                $table->string('paypal_confirm_token', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('organizations', 'paypal_confirm_sent_at')) {
                $table->timestamp('paypal_confirm_sent_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            foreach (['paypal_confirm_sent_at', 'paypal_confirm_token'] as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
