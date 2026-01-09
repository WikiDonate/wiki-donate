<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ngo_causes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cause_id')->constrained()->onDelete('cascade');
            $table->foreignId('ngo_id')->constrained()->onDelete('cascade');
            $table->decimal('percentage', 5, 2);
            $table->timestamps();

            $table->unique(['cause_id', 'ngo_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('ngo_causes');
    }
};
