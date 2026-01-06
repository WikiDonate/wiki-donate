<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->foreignId('cause_id')->nullable()->constrained('causes')->onDelete('set null');
        });
    }
    
    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->dropForeign(['cause_id']);
            $table->dropColumn('cause_id');
        });
    }
    
};
