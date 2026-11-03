<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payout destinations are resolved by name when a formula row has no
     * organization_id, and `resolve()` uses `first()` — with two same-named
     * registry rows that can silently pick a row an admin never verified.
     *
     * Make `normalized_name` canonical (lowercased, whitespace-collapsed) and
     * unique so an ambiguous payout destination is impossible. Existing rows
     * are normalized first; if a genuine duplicate is found the unique index
     * is skipped and a warning is logged (never fail a deploy on legacy data).
     */
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        // Canonicalize every existing row to match Organization::normalizeName().
        DB::table('organizations')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $row->name)));
                if ($normalized !== $row->normalized_name) {
                    DB::table('organizations')->where('id', $row->id)->update([
                        'normalized_name' => $normalized,
                    ]);
                }
            }
        });

        $duplicates = DB::table('organizations')
            ->selectRaw('normalized_name, COUNT(*) as total')
            ->groupBy('normalized_name')
            ->having('total', '>', 1)
            ->pluck('normalized_name');

        if ($duplicates->isNotEmpty()) {
            logger()->warning('organizations.normalized_name still has duplicates; unique index skipped.', [
                'duplicates' => $duplicates->all(),
            ]);

            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            // Replace the non-unique lookup index with a unique constraint
            // (the unique index also serves the name lookups).
            try {
                $table->dropIndex(['normalized_name']);
            } catch (Throwable $e) {
                // Index already absent (fresh schema) — the unique one below
                // covers the same column.
            }

            $table->unique('normalized_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            try {
                $table->dropUnique(['normalized_name']);
            } catch (Throwable $e) {
                // Never created (duplicates present) — nothing to drop.
            }

            try {
                $table->index('normalized_name');
            } catch (Throwable $e) {
                // Index already present.
            }
        });
    }
};
