<?php

namespace App\Services\Charity;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Upsert organizations from donation formula rows.
 *
 * Matching priority:
 *   1. EIN, when provided.
 *   2. Normalized organization name.
 *
 * The returned rows have `organization_id` populated for persistence back
 * into the formula JSON.
 */
class CharitySearchService
{
    /**
     * @param  array<int, array{organization: string, percentage: float|int, ein?: string|null, organization_id?: int|null}>  $rows
     * @return array<int, array{organization: string, percentage: float|int, ein?: string|null, organization_id: int|null}>
     */
    public function upsertFromFormulaRows(array $rows, ?int $actorId = null): array
    {
        return collect($rows)
            ->map(function (array $row) use ($actorId) {
                $name = trim((string) ($row['organization'] ?? ''));
                $ein = $this->cleanEin($row['ein'] ?? null);
                $providedId = $row['organization_id'] ?? null;

                if ($name === '') {
                    return [
                        ...$row,
                        'ein' => $ein,
                        'organization_id' => $providedId,
                    ];
                }

                // If the caller supplied a valid organization_id, trust it.
                if (! empty($providedId)) {
                    $organization = Organization::find($providedId);
                    if ($organization) {
                        return [
                            ...$row,
                            'ein' => $ein,
                            'organization_id' => $organization->id,
                        ];
                    }
                }

                $organization = $this->upsertOrganization($name, $ein, $actorId);

                return [
                    ...$row,
                    'ein' => $ein,
                    'organization_id' => $organization?->id,
                ];
            })
            ->all();
    }

    /**
     * Upsert a single organization by EIN or normalized name.
     */
    public function upsertOrganization(string $name, ?string $ein = null, ?int $actorId = null): Organization
    {
        $normalized = Organization::normalizeName($name);
        $cleanEin = $this->cleanEin($ein);

        return DB::transaction(function () use ($name, $normalized, $cleanEin, $actorId) {
            // 1. Try EIN match first.
            if (! empty($cleanEin)) {
                $organization = Organization::where('ein', $cleanEin)->lockForUpdate()->first();
                if ($organization) {
                    return $this->updateOrganization($organization, $name, $cleanEin, $actorId);
                }
            }

            // 2. Fall back to normalized name match.
            $organization = Organization::where('normalized_name', $normalized)->lockForUpdate()->first();
            if ($organization) {
                return $this->updateOrganization($organization, $name, $cleanEin, $actorId);
            }

            // 3. Create new organization.
            $organization = Organization::create([
                'name' => $name,
                'normalized_name' => $normalized,
                'ein' => $cleanEin,
                'created_by_id' => $actorId,
                'updated_by_id' => $actorId,
            ]);

            $this->audit('created', $organization, null, $actorId);

            return $organization;
        });
    }

    /**
     * Update an existing organization while preserving verification state.
     */
    private function updateOrganization(Organization $organization, string $name, ?string $ein, ?int $actorId): Organization
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
            $this->audit('updated', $organization, $before, $actorId, $after);
        }

        return $organization;
    }

    /**
     * Strip non-alphanumeric characters from an EIN and return null if empty.
     */
    private function cleanEin(?string $ein): ?string
    {
        if ($ein === null) {
            return null;
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/u', '', $ein);

        return $clean === '' || $clean === false ? null : strtoupper($clean);
    }

    /**
     * Write an audit log entry for an organization change.
     */
    private function audit(string $action, Organization $organization, ?array $before, ?int $actorId, ?array $after = null): void
    {
        Log::channel('transaction')->info('organization_upsert', [
            'action' => $action,
            'organization_id' => $organization->id,
            'organization_uuid' => $organization->uuid,
            'actor_id' => $actorId,
            'before' => $before,
            'after' => $after ?? $organization->only(['name', 'normalized_name', 'ein']),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
