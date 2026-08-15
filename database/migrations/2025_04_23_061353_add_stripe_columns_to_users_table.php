<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'customer_id')) {
                $table->string('customer_id')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'card_id')) {
                $table->string('card_id')->nullable()->after('customer_id');
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'customer_id')) {
                $table->dropColumn('customer_id');
            }
            if (Schema::hasColumn('users', 'card_id')) {
                $table->dropColumn('card_id');
            }
        });
    }
};
