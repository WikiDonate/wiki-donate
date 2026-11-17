<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Put created_at / updated_at back at the end of `donations`.
     *
     * Three columns were added in later migrations without an `AFTER`, so on
     * MySQL they landed after the timestamps. sqlite (tests) rebuilds fresh and
     * keeps whatever order the migrations created, so this is MySQL-only and
     * safe to re-run (MODIFY ... AFTER is idempotent).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('donations')) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `donations`
                MODIFY COLUMN `donation_formula_id` bigint unsigned DEFAULT NULL AFTER `metadata`,
                MODIFY COLUMN `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `donation_formula_id`,
                MODIFY COLUMN `gateway_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `payment_method`,
                MODIFY COLUMN `created_at` timestamp NULL DEFAULT NULL AFTER `gateway_reference`,
                MODIFY COLUMN `updated_at` timestamp NULL DEFAULT NULL AFTER `created_at`
            SQL);
    }

    /**
     * Position is cosmetic and the original order is not worth restoring.
     */
    public function down(): void
    {
        //
    }
};
