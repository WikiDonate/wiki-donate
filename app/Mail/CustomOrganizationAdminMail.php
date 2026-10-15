<?php

namespace App\Mail;

use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomOrganizationAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public DonationFormula $formula,
        public User $creator,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New custom organization needs review: {$this->organization->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.custom-organization-admin',
            with: [
                'organization' => $this->organization,
                'formula' => $this->formula,
                'creator' => $this->creator,
            ],
        );
    }
}
