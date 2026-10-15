<?php

namespace App\Services\Organization;

use App\Mail\CustomOrganizationAdminMail;
use App\Mail\CustomOrganizationUserMail;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Notify creator + admins when a formula introduces a custom
 * (newly created, unverified) organization name.
 *
 * Never throws: mail failures are reported so formula save/update
 * can never break because of notifications.
 */
class CustomOrganizationNotifier
{
    /**
     * @param  Collection<int, Organization>  $created
     */
    public static function notifyIfNeeded(Collection $created, DonationFormula $formula, ?User $creator): void
    {
        if ($created->isEmpty() || ! $creator || empty($creator->email)) {
            return;
        }

        $custom = $created->filter(fn (Organization $org) => ! $org->isVerified())->values();

        if ($custom->isEmpty()) {
            return;
        }

        $admins = collect();
        try {
            $admins = User::role('Admin')->whereNotNull('email')->get();
        } catch (\Throwable $e) {
            // Role may not exist in tests/seeds — user mail still goes out.
            report($e);
        }

        foreach ($custom as $organization) {
            try {
                Mail::mailer('failover')->to($creator->email)->queue(new CustomOrganizationUserMail($organization, $formula));
            } catch (\Throwable $e) {
                report($e);
            }

            foreach ($admins as $admin) {
                if ($admin->email === $creator->email) {
                    continue;
                }
                try {
                    Mail::mailer('failover')->to($admin->email)->queue(new CustomOrganizationAdminMail($organization, $formula, $creator));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            TransactionLog::record('organization.custom_notified', [
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'actor_id' => $creator->id,
                'message' => "Custom organization notified: {$organization->name}",
                'after' => ['organization_id' => $organization->id, 'formula_id' => $formula->id],
            ]);
        }
    }
}
