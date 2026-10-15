<?php

namespace App\Mail;

use App\Models\DonationFormula;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomOrganizationUserMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public DonationFormula $formula,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "We received your custom organization: {$this->organization->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.custom-organization-user',
            with: [
                'organization' => $this->organization,
                'formula' => $this->formula,
            ],
        );
    }
}
