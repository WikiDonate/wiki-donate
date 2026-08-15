<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('talk_revisions');
        Schema::dropIfExists('talks');
    }

    public function down(): void
    {
        // No reverse
    }
};
