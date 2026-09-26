<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationVerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Organization $organization) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'WikiDonate: Organization Payout Verification',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.organization-verified',
            with: [
                'organizationName' => $this->organization->name,
                'paypalEmail' => $this->organization->paypal_email,
                'verifiedAt' => $this->organization->verified_at?->toDateTimeString(),
            ],
        );
    }
}
