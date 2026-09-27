<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\TransactionLog;
use Illuminate\Support\Facades\DB;

/**
 * Registry service for resolving and upserting organizations.
 *
 * Upserts organization records from donation formula rows, keyed by EIN
 * or normalized name, and writes structured transaction logs.
 */
class OrganizationRegistryService
{
    /**
     * Upsert organizations from an array of donation formula rows.
     *
     * @param  array<int, array{organization: string, percentage: float|int, ein?: string|null, organization_id?: int|null}>  $rows
     * @return array<int, array{organization: string, percentage: float|int, ein?: string|null, organization_id: int|null}>
     */
    public static function upsertFromFormulaRows(array $rows, ?int $actorId = null): array
    {
        return collect($rows)
            ->map(function (array $row) use ($actorId) {
                $organization = self::upsertFromFormulaRow($row, $actorId);

                TransactionLog::record('organization.upserted', [
                    'subject_type' => Organization::class,
                    'subject_id' => $organization->id,
                    'actor_id' => $actorId,
                    'message' => "Organization linked from formula: {$organization->name}",
                    'after' => [
                        'organization_id' => $organization->id,
                        'ein' => $organization->ein,
                        'percentage' => $row['percentage'] ?? null,
                    ],
                ]);

                return [
                    ...$row,
                    'ein' => $organization->ein,
                    'website' => $organization->website,
                    'organization_id' => $organization->id,
                ];
            })
            ->all();
    }

    /**
     * Upsert a single organization from a formula row.
     *
     * @param  array{organization: string, percentage: float|int, ein?: string|null, organization_id?: int|null}  $row
     */
    public static function upsertFromFormulaRow(array $row, ?int $actorId = null): Organization
    {
        $name = trim((string) ($row['organization'] ?? ''));
        $ein = self::cleanEin($row['ein'] ?? null);
        $providedId = $row['organization_id'] ?? null;
        $website = self::cleanWebsite($row['website'] ?? null);

        return DB::transaction(function () use ($name, $ein, $providedId, $website, $actorId) {
            // 1. EIN match.
            if (! empty($ein)) {
                $organization = Organization::where('ein', $ein)->lockForUpdate()->first();
                if ($organization) {
                    return self::touch($organization, $name, $ein, $website, $actorId);
                }
            }

            // 2. Normalized name match.
            $normalized = Organization::normalizeName($name);
            $organization = Organization::where('normalized_name', $normalized)->lockForUpdate()->first();
            if ($organization) {
                return self::touch($organization, $name, $ein, $website, $actorId);
            }

            // 3. Caller-supplied ID.
            if (! empty($providedId)) {
                $organization = Organization::where('id', $providedId)->lockForUpdate()->first();
                if ($organization) {
                    return self::touch($organization, $name, $ein, $website, $actorId);
                }
            }

            // 4. Create.
            $organization = Organization::create([
                'name' => $name,
                'normalized_name' => $normalized,
                'ein' => $ein,
                'website' => $website,
                'created_by_id' => $actorId,
                'updated_by_id' => $actorId,
            ]);

            TransactionLog::record('organization.created', [
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'actor_id' => $actorId,
                'message' => "Organization registered: {$organization->name}",
                'after' => $organization->only(['name', 'normalized_name', 'ein', 'payout_status', 'paypal_email']),
            ]);

            return $organization;
        });
    }

    private static function touch(Organization $organization, string $name, ?string $ein, ?string $website, ?int $actorId): Organization
    {
        $before = $organization->only(['name', 'normalized_name', 'ein', 'website']);

        $organization->name = $name;
        $organization->normalized_name = Organization::normalizeName($name);
        if ($ein !== null && $ein !== '') {
            $organization->ein = $ein;
        }
        // Fill in a missing website, never overwrite a curated one with blank.
        if (($website ?? '') !== '' && empty($organization->website)) {
            $organization->website = $website;
        }
        $organization->updated_by_id = $actorId;
        $organization->save();

        $after = $organization->only(['name', 'normalized_name', 'ein', 'website']);

        if ($before !== $after) {
            TransactionLog::record('organization.updated', [
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'actor_id' => $actorId,
                'message' => "Organization updated: {$organization->name}",
                'before' => $before,
                'after' => $after,
            ]);
        }

        return $organization;
    }

    private static function cleanEin(?string $ein): ?string
    {
        if ($ein === null) {
            return null;
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/u', '', $ein);

        return $clean === '' || $clean === false ? null : strtoupper($clean);
    }

    /**
     * Normalize a directory-provided website to an absolute URL, or null.
     * Directory data is never trusted blindly for href output.
     */
    private static function cleanWebsite(mixed $website): ?string
    {
        $website = trim((string) ($website ?? ''));

        if ($website === '' || str_contains($website, ' ')) {
            return null;
        }

        if (! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        if (filter_var($website, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return mb_substr($website, 0, 500);
    }
}
