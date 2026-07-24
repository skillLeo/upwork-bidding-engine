<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email side of a bell notification (P5 Notification preferences) — a
 * new lead or a follow-up reminder, fanned out per-member by
 * NotificationService according to their own email_on_* preference. Queued:
 * unlike OtpCodeMail this is never on a critical path, so it rides the same
 * per-minute queue drain as everything else on this host.
 */
class LeadNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public ?string $body,
        public ?string $url,
        public string $appUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead-notification',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'link' => $this->url ? rtrim($this->appUrl, '/').$this->url : null,
            ],
        );
    }
}
