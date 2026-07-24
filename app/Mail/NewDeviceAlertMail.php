<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "A new device signed in to your account."
 *
 * Not ShouldQueue itself — the JOB that sends it is queued (see
 * SendNewDeviceAlertJob), which keeps the sign-in response fast while
 * leaving this mailable directly testable.
 */
class NewDeviceAlertMail extends Mailable
{
    public function __construct(
        public string $userName,
        public string $device,
        public ?string $location,
        public ?string $ip,
        public string $signedInAt,
        public string $revokeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-in to your SkillLeo account');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new-device-alert');
    }
}
