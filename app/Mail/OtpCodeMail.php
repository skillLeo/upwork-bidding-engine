<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously (no ShouldQueue) — a login OTP is worthless if it
 * doesn't land within seconds, and this app already routes everything
 * time-sensitive inline rather than through the per-minute cron/queue.
 */
class OtpCodeMail extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your SkillLeo sign-in code: {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp-code',
            with: ['code' => $this->code],
        );
    }
}
