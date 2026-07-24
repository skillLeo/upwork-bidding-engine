<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    public function __construct(
        public string $workspaceName,
        public string $invitedByName,
        public string $roleLabel,
        public string $acceptUrl,
        public string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to {$this->workspaceName} on SkillLeo");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitation');
    }
}
