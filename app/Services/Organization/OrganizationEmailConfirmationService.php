<?php

namespace App\Services\Organization;

use App\Mail\OrganizationEmailConfirmationMail;
use App\Models\Organization;
use App\Models\TransactionLog;
use Exception;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Token-based PayPal email confirmation for payout destinations.
 *
 * Flow: admin sets/changes the receiving email -> a confirmation link is
 * mailed to that address -> the recipient clicks it -> the organization
 * becomes verified. Only a click proves the address exists and is
 * controlled by someone who reads it.
 *
 * Links expire after 7 days. Setting a new email invalidates any pending
 * token by issuing a fresh one.
 */
class OrganizationEmailConfirmationService
{
    public const TOKEN_TTL_DAYS = 7;

    /**
     * Issue a fresh token and mail the confirmation link.
     *
     * Never throws: mail failures are logged so they can't break the admin
     * action that triggered the send.
     */
    public function send(Organization $organization, ?int $actorId = null): void
    {
        if (empty($organization->paypal_email)) {
            return;
        }

        $organization->forceFill([
            'paypal_confirm_token' => Str::random(48),
            'paypal_confirm_sent_at' => now(),
        ])->save();

        try {
            Mail::to($organization->paypal_email)->queue(
                new OrganizationEmailConfirmationMail($organization, $this->confirmUrl($organization))
            );
        } catch (Exception $e) {
            report($e);
        }

        TransactionLog::record('organization.confirmation_sent', [
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'actor_id' => $actorId,
            'message' => "Confirmation link sent to {$organization->paypal_email} for {$organization->name}",
            'after' => ['paypal_email' => $organization->paypal_email],
        ]);
    }

    /**
     * Confirm the pending email for a token. Throws when the token is
     * unknown, expired, or the email was changed since sending.
     */
    public function confirm(string $token): Organization
    {
        $organization = Organization::where('paypal_confirm_token', $token)->first();

        if (! $organization || empty($organization->paypal_email)) {
            throw new Exception('This confirmation link is invalid.');
        }

        $sentAt = $organization->paypal_confirm_sent_at;
        if (! $sentAt || $sentAt->lt(now()->subDays(self::TOKEN_TTL_DAYS))) {
            throw new Exception('This confirmation link has expired. Please ask for a new one.');
        }

        $organization->forceFill([
            'payout_status' => 'verified',
            'verified_at' => now(),
            'paypal_confirm_token' => null,
            'paypal_confirm_sent_at' => null,
        ])->save();

        TransactionLog::record('organization.verified', [
            'subject_type' => Organization::class,
            'subject_id' => $organization->id,
            'actor_id' => null,
            'actor_role' => 'System',
            'message' => "Organization verified via email confirmation: {$organization->name} ({$organization->paypal_email})",
            'after' => ['payout_status' => 'verified'],
        ]);

        return $organization;
    }

    public function confirmUrl(Organization $organization): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $base.'/organization/confirm-email?token='.$organization->paypal_confirm_token;
    }
}
