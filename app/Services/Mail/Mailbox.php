<?php

namespace App\Services\Mail;

/**
 * The mailbox surface the Vollna email intake depends on. Exists so the
 * poller can be tested on a machine without the imap extension by binding
 * a fake implementation.
 */
interface Mailbox
{
    /**
     * @return array<int, MailMessage>
     */
    public function unreadFromSender(int $limit = 25): array;

    /**
     * @return array<int, MailMessage>
     */
    public function recentFromSender(int $limit = 3): array;

    public function markRead(string $uid): void;
}
