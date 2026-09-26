<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\TransactionLog;
use Exception;

/**
 * Org registry upsert — keeps `organizations` in sync with the free-text
 * formula rows editors type.
 *
 * Matching is EIN-first, then normalized name. The EIN is the stable dedupe
 * key; nullable EINs (free-text entries) fall back to a normalized-name match
 * so a genuine charity picked from autocomplete gains the same org row when
 * typed differently later.
 */
class OrganizationRegistryService
{
    private const ROLE_EDITOR = 'Editor';

    /**
     * Upsert an organization from a formula row and return its id.
     *
     * Matches by EIN when present, otherwise by normalized name. City/state
     * are refreshed only when the row carries a verified EIN (free-text rows
     * supply no location data, and stale editor-typed names must not overwrite
     * directory data).
     *
     * @param  array  $row  formula row: {organization: string, ein?: string|null, city?: string|null, state?: string|null}
     */
    public function upsertFromFormulaRow(array $row): int
    {
        $name = trim((string) ($row['organization'] ?? ''));
        if ($name === '') {
            throw new Exception('Organization name is required.');
        }

        $ein = $this->normalizeEin($row['ein'] ?? null);

        $org = $ein !== null
            ? Organization::where('ein', $ein)->first()
            : Organization::where('name', '=', $name)->first();

        $created = $org === null;
        $beforeSnapshot = $org ? $this->snapshot($org) : null;

        if ($created) {
            $org = Organization::create([
                'name' => $name,
                'ein' => $ein,
                'city' => isset($row['city']) && $ein !== null ? trim($row['city']) : null,
                'state' => isset($row['state']) && $ein !== null ? trim($row['state']) : null,
                'paypal_email' => null,
                'payout_status' => 'unverified',
            ]);

            TransactionLog::record('organization.upserted', array_filter([
                'organization_id' => $org->id,
                'name' => $name,
                'ein' => $ein,
                'city' => $org->city,
                'state' => $org->state,
                'actor_id' => $this->actorId(),
                'actor_role' => self::ROLE_EDITOR,
            ]));

            return $org->id;
        }

        $changes = [];
        if ($ein !== null && $org->ein !== $ein) {
            $changes['ein'] = $ein;
        }
        if (isset($row['city']) && $ein !== null && $org->city !== trim($row['city'])) {
            $changes['city'] = trim($row['city']);
        }
        if (isset($row['state']) && $ein !== null && $org->state !== trim($row['state'])) {
            $changes['state'] = trim($row['state']);
        }

        // Update the name only when it actually differs (case-insensitive) —
        // case-only drift after an EIN match is not worth a ledger row.
        if (mb_strtolower($org->name) !== mb_strtolower($name)) {
            $changes['name'] = $name;
        }

        if ($changes !== []) {
            $after = array_merge($this->snapshot($org), $changes);
            $org->update($changes);

            TransactionLog::record('organization.updated', [
                'organization_id' => $org->id,
                'name' => $org->name,
                'ein' => $org->ein,
                'before' => $beforeSnapshot,
                'after' => $after,
                'actor_id' => $this->actorId(),
                'actor_role' => self::ROLE_EDITOR,
            ]);
        }

        return $org->id;
    }

    /**
     * Strip dashes/spaces from EINs; null when empty so free-text rows stay
     * nullable and keep matching on name.
     */
    private function normalizeEin(mixed $ein): ?string
    {
        if ($ein === null) {
            return null;
        }

        $ein = preg_replace('/[^0-9]/', '', (string) $ein);

        return $ein === '' ? null : $ein;
    }

    private function snapshot(Organization $org): array
    {
        return [
            'name' => $org->name,
            'ein' => $org->ein,
            'city' => $org->city,
            'state' => $org->state,
            'paypal_email' => $org->paypal_email,
            'payout_status' => $org->payout_status,
            'verified_at' => $org->verified_at?->toDateTimeString(),
        ];
    }

    private function actorId(): ?int
    {
        return auth('sanctum')->id() ?? auth()->id();
    }

    /**
     * Normalize an organization name into a stable comparison key, shared
     * with the payout service so allocation lookups match registry entries.
     */
    public static function makeKey(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }
}
