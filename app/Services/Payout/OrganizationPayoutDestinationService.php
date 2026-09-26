<?php

namespace App\Services\Payout;

use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\TransactionLog;

/**
 * Resolves the payout destination (organizations.paypal_email) from the
 * org registry and enforces the payout guard: payouts are blocked unless
 * the org is verified AND has a PayPal receiving email.
 *
 * The ledger stays append-only; this service never rewrites history.
 */
class OrganizationPayoutDestinationService
{
    /**
     * Resolve the destination org for a payout allocation.
     *
     * Match order: explicit organization_id on the formula row, else the
     * normalized-name match against the registry.
     *
     * @return Organization|null null when the org is not (yet) in the registry
     */
    public function resolve(?int $organizationId, string $organizationName): ?Organization
    {
        if ($organizationId !== null) {
            $org = Organization::find($organizationId);
            if ($org) {
                return $org;
            }
        }

        // Name fallback: exact first, then case-insensitive so formula rows
        // with different casing still resolve to the same registry entry.
        $org = Organization::where('name', '=', $organizationName)->first();
        if ($org) {
            return $org;
        }

        return Organization::whereRaw('LOWER(name) = ?', [mb_strtolower($organizationName)])->first();
    }

    /**
     * Throws when the destination is not payout-ready.
     *
     * @throws \Exception with a message safe to surface to admins
     */
    public function guardPayoutDestination(?int $organizationId, string $organizationName, int $actorId): Organization
    {
        $org = $this->resolve($organizationId, $organizationName);

        if (! $org) {
            TransactionLog::record('payout.blocked', [
                'actor_id' => $actorId,
                'actor_role' => 'Admin',
                'subject_type' => OrganizationPayout::class,
                'message' => 'Payout blocked: organization not in registry — '.$organizationName,
                'meta' => ['organization_name' => $organizationName, 'reason' => 'not_in_registry'],
            ]);

            throw new \Exception('This organization has no verified payout destination yet. Verify it in the Organizations page first.');
        }

        if (! filled($org->paypal_email)) {
            TransactionLog::record('payout.blocked', [
                'actor_id' => $actorId,
                'actor_role' => 'Admin',
                'subject_type' => Organization::class,
                'subject_id' => $org->id,
                'message' => 'Payout blocked: missing PayPal email — '.$org->name,
                'meta' => ['organization_id' => $org->id, 'reason' => 'missing_paypal_email'],
            ]);

            throw new \Exception('This organization has no PayPal receiving email set. Set it in the Organizations page first.');
        }

        if ($org->payout_status !== 'verified') {
            TransactionLog::record('payout.blocked', [
                'actor_id' => $actorId,
                'actor_role' => 'Admin',
                'subject_type' => Organization::class,
                'subject_id' => $org->id,
                'message' => 'Payout blocked: organization not verified — '.$org->name,
                'meta' => ['organization_id' => $org->id, 'reason' => 'not_verified'],
            ]);

            throw new \Exception('This organization is not verified yet. Verify it in the Organizations page before paying out.');
        }

        return $org;
    }
}
