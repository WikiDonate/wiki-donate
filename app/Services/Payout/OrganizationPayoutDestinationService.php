<?php

namespace App\Services\Payout;

use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\TransactionLog;
use Exception;

/**
 * Resolve and enforce payout destination requirements.
 *
 * Payouts may only proceed when the organization referenced by the formula
 * row is verified and has a PayPal receiving email on file. Each block is
 * logged as a payout.blocked audit row.
 */
class OrganizationPayoutDestinationService
{
    /**
     * Resolve the organization for an allocation and enforce payout rules.
     *
     * @throws Exception when the destination is not verified or missing PayPal email.
     */
    public function resolve(DonationFormula $formula, string $organizationName, ?int $actorId = null): Organization
    {
        $organization = $this->lookup($formula, $organizationName);

        if (! $organization) {
            throw new Exception('No registered organization found for this allocation.');
        }

        if ($organization->payout_status !== 'verified') {
            $this->block($organization, 'Organization is not verified for payouts.', $actorId);

            throw new Exception('Organization is not verified for payouts.');
        }

        if (empty($organization->paypal_email)) {
            $this->block($organization, 'Organization does not have a PayPal receiving email.', $actorId);

            throw new Exception('Organization does not have a PayPal receiving email.');
        }

        return $organization;
    }

    /**
     * Look up the organization using the formula row's organization_id first,
     * then fall back to name/EIN matching.
     */
    private function lookup(DonationFormula $formula, string $organizationName): ?Organization
    {
        $items = $formula->formula ?? [];

        $matchedRow = collect($items)
            ->first(fn ($row) => is_array($row) && Organization::normalizeName((string) ($row['organization'] ?? '')) === Organization::normalizeName($organizationName));

        $organizationId = is_array($matchedRow) ? ($matchedRow['organization_id'] ?? null) : null;
        $ein = is_array($matchedRow) ? ($matchedRow['ein'] ?? null) : null;

        if (! empty($organizationId)) {
            $organization = Organization::find($organizationId);
            if ($organization) {
                return $organization;
            }
        }

        if (! empty($ein)) {
            $clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/u', '', $ein) ?? '');
            $organization = Organization::where('ein', $clean)->first();
            if ($organization) {
                return $organization;
            }
        }

        return Organization::where('normalized_name', Organization::normalizeName($organizationName))->first();
    }

    /**
     * Record a blocked payout attempt.
     */
    private function block(Organization $organization, string $reason, ?int $actorId = null): void
    {
        TransactionLog::record(
            'payout.blocked',
            $organization,
            before: $organization->only(['payout_status', 'paypal_email']),
            after: null,
            actorId: $actorId ?? auth()->id(),
            note: $reason,
        );
    }
}
