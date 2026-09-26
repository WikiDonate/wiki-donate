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

                return [
                    ...$row,
                    'ein' => $organization->ein,
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

        return DB::transaction(function () use ($name, $ein, $providedId, $actorId) {
            // 1. EIN match.
            if (! empty($ein)) {
                $organization = Organization::where('ein', $ein)->lockForUpdate()->first();
                if ($organization) {
                    return self::touch($organization, $name, $ein, $actorId);
                }
            }

            // 2. Normalized name match.
            $normalized = Organization::normalizeName($name);
            $organization = Organization::where('normalized_name', $normalized)->lockForUpdate()->first();
            if ($organization) {
                return self::touch($organization, $name, $ein, $actorId);
            }

            // 3. Caller-supplied ID.
            if (! empty($providedId)) {
                $organization = Organization::where('id', $providedId)->lockForUpdate()->first();
                if ($organization) {
                    return self::touch($organization, $name, $ein, $actorId);
                }
            }

            // 4. Create.
            $organization = Organization::create([
                'name' => $name,
                'normalized_name' => $normalized,
                'ein' => $ein,
                'created_by_id' => $actorId,
                'updated_by_id' => $actorId,
            ]);

            TransactionLog::record(
                'organization.created',
                $organization,
                after: $organization->only(['name', 'normalized_name', 'ein', 'payout_status', 'paypal_email']),
                actorId: $actorId,
            );

            return $organization;
        });
    }

    private static function touch(Organization $organization, string $name, ?string $ein, ?int $actorId): Organization
    {
        $before = $organization->only(['name', 'normalized_name', 'ein']);

        $organization->name = $name;
        $organization->normalized_name = Organization::normalizeName($name);
        if ($ein !== null && $ein !== '') {
            $organization->ein = $ein;
        }
        $organization->updated_by_id = $actorId;
        $organization->save();

        $after = $organization->only(['name', 'normalized_name', 'ein']);

        if ($before !== $after) {
            TransactionLog::record(
                'organization.updated',
                $organization,
                before: $before,
                after: $after,
                actorId: $actorId,
            );
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
}
