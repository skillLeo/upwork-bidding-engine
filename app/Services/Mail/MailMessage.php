<?php

namespace App\Services\Mail;

/**
 * One fetched message, already MIME-decoded.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $uid,
        public readonly string $from,
        public readonly string $subject,
        public readonly string $date,
        public readonly string $html,
        public readonly string $text,
    ) {}

    /**
     * The richest available body — Vollna's job details live in the HTML
     * part; the text part is the fallback.
     */
    public function body(): string
    {
        return $this->html !== '' ? $this->html : $this->text;
    }
}
