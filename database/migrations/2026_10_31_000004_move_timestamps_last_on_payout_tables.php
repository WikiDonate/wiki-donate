<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure `created_at` / `updated_at` are the LAST two columns on the
     * tables touched by the payout work.
     *
     * Columns added with a plain `$table->...()` are appended after the
     * timestamps on MySQL, so `organization_payouts.fee_*`/`net_amount` and
     * `organizations.website` ended up AFTER the timestamps. This migration
     * moves the timestamps to the end WITHOUT reordering any other column —
     * every business column keeps its existing position and meaning.
     *
     * MySQL-only: MariaDB/MySQL support MODIFY ... AFTER. Fresh sqlite schemas
     * (used by the test suite) build from the create migrations and are fine.
     */
    /**
     * Canonical column order per table. Any column present in the live schema
     * but missing from this list is appended after the known ones (before the
     * timestamps), so the migration is safe on schemas that grew extra columns.
     */
    private const CANONICAL = [
        'organization_payouts' => [
            'id', 'uuid', 'donation_formula_id', 'organization_name', 'organization_key',
            'organization_id', 'destination_paypal_email', 'amount', 'currency', 'type',
            'status', 'paid_at', 'submitted_at', 'attempt_count', 'reversed_at',
            'payout_batch_id', 'payout_item_id', 'provider_status', 'failure_reason',
            'actor_id', 'payment_method', 'note', 'fee_percent', 'fee_amount', 'net_amount',
        ],
        'organizations' => [
            'id', 'uuid', 'name', 'normalized_name', 'ein', 'city', 'state', 'country',
            'paypal_email', 'payout_methods', 'payout_status', 'verified_at',
            'created_by_id', 'updated_by_id', 'website',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CANONICAL as $table => $ordered) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $live = $this->liveColumns($table);
            $timestamps = array_values(array_intersect(['created_at', 'updated_at'], $live));

            // Desired order: canonical columns that exist, then any extra live
            // column (unknown/newer), then the timestamps very last.
            $desired = array_values(array_filter(
                $ordered,
                fn ($column) => in_array($column, $live, true)
            ));

            foreach ($live as $column) {
                if (! in_array($column, $desired, true) && ! in_array($column, $timestamps, true)) {
                    $desired[] = $column;
                }
            }

            $previous = null;
            foreach (array_merge($desired, $timestamps) as $index => $column) {
                if ($index === 0) {
                    // Anchor the first column at the very front.
                    $this->moveFirst($table, $column);
                } else {
                    $this->moveAfter($table, $column, $previous);
                }
                $previous = $column;
            }
        }
    }

    public function down(): void
    {
        // Column order is cosmetic; nothing to reverse.
    }

    /**
     * Live column names in their current physical order.
     *
     * @return array<int, string>
     */
    private function liveColumns(string $table): array
    {
        return array_map(
            fn ($column) => $column->COLUMN_NAME,
            DB::select(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table]
            )
        );
    }

    /**
     * Reposition a column at the very front of the table.
     */
    private function moveFirst(string $table, string $column): void
    {
        $definition = $this->columnDefinition($table, $column);

        if ($definition === null) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN {$definition} FIRST");
    }

    /**
     * Reposition a column AFTER $after.
     */
    private function moveAfter(string $table, string $column, string $after): void
    {
        $definition = $this->columnDefinition($table, $column);

        if ($definition === null) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN {$definition} AFTER `{$after}`");
    }

    /**
     * Full `column_name TYPE ...` clause for MODIFY, read from the live schema
     * so nullability, defaults and comments are preserved.
     */
    private function columnDefinition(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if (! $row) {
            return null;
        }

        $definition = '`'.$row->COLUMN_NAME.'` '.$row->COLUMN_TYPE;
        $definition .= $row->IS_NULLABLE === 'NO' ? ' NOT NULL' : ' NULL';

        if ($row->COLUMN_DEFAULT !== null) {
            // Quote string defaults; leave numeric/function defaults bare.
            $default = is_numeric($row->COLUMN_DEFAULT)
                ? $row->COLUMN_DEFAULT
                : "'".$row->COLUMN_DEFAULT."'";
            $definition .= ' DEFAULT '.$default;
        }

        if (str_contains(strtolower((string) $row->EXTRA), 'auto_increment')) {
            $definition .= ' AUTO_INCREMENT';
        }

        if (! empty($row->COLUMN_COMMENT)) {
            $definition .= " COMMENT '".str_replace("'", "''", $row->COLUMN_COMMENT)."'";
        }

        return $definition;
    }
};
