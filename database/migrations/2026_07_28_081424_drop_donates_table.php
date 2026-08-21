<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('donates');
    }

    public function down(): void
    {
        // No reverse — table and model permanently removed
    }
};
