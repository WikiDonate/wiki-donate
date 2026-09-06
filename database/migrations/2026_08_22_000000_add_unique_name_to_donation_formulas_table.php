<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Rename duplicate named formulas (e.g. "Shahaj Formula" -> "Shahaj Formula 2")
        //    so the unique index can be added without deleting rows that have donations.
        $duplicates = DB::table('donation_formulas as f1')
            ->join('donation_formulas as f2', function ($join) {
                $join->on('f1.article_id', '=', 'f2.article_id')
                    ->on('f1.user_id', '=', 'f2.user_id')
                    ->on('f1.name', '=', 'f2.name')
                    ->on('f1.id', '>', 'f2.id');
            })
            ->whereNotNull('f1.name')
            ->where('f1.name', '!=', '')
            ->select('f1.id', 'f1.name')
            ->orderBy('f1.id')
            ->get();

        $suffixes = [];
        foreach ($duplicates as $dup) {
            $key = strtolower($dup->name);
            $suffixes[$key] = ($suffixes[$key] ?? 1) + 1;
            DB::table('donation_formulas')
                ->where('id', $dup->id)
                ->update(['name' => $dup->name.' '.$suffixes[$key]]);
        }

        // 2. For each article+user, keep a single empty/null-name formula
        //    (prefer one with donations, else the lowest id) and delete the
        //    remaining rows that have NO donations. Rows with donations are
        //    never deleted (FK is restricted), so the unique index below will
        //    fail loudly if a conflicting donation-backed duplicate remains.
        $groups = DB::table('donation_formulas')
            ->where(function ($q) {
                $q->whereNull('name')->orWhere('name', '=', '');
            })
            ->select('article_id', 'user_id')
            ->groupBy('article_id', 'user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('donation_formulas')
                ->where('article_id', $group->article_id)
                ->where('user_id', $group->user_id)
                ->where(function ($q) {
                    $q->whereNull('name')->orWhere('name', '=', '');
                })
                ->orderBy('id')
                ->get(['id']);

            $keep = null;
            $deletable = collect();
            foreach ($rows as $row) {
                $hasDonations = DB::table('donations')->where('donation_formula_id', $row->id)->exists();
                if ($hasDonations) {
                    $keep ??= $row->id;
                } else {
                    $deletable->push($row->id);
                }
            }
            $keep ??= $rows->first()->id;

            foreach ($deletable as $id) {
                if ($id !== $keep) {
                    DB::table('donation_formulas')->where('id', $id)->delete();
                }
            }
        }

        // 3. Add the unique index now that names are deduplicated
        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->unique(['article_id', 'user_id', 'name'], 'donation_formulas_article_user_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donation_formulas', function (Blueprint $table) {
            $table->dropUnique('donation_formulas_article_user_name_unique');
        });
    }
};
