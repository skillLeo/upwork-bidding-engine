<?php

namespace App\Services\Mail;

use App\Services\SettingsService;

/**
 * Thin wrapper over PHP's native imap_* functions (the extension is
 * present on the production host, so no extra dependency). Kept behind
 * this small surface for two reasons: the dev machine has no imap
 * extension, so tests bind a fake in its place; and the poller should
 * never care about IMAP stream mechanics.
 *
 * The App Password is read from Settings on every call and is never
 * logged, never returned, and never included in an exception message.
 */
class ImapMailbox implements Mailbox
{
    public function __construct(protected SettingsService $settings) {}

    /**
     * Unread messages from the configured sender, newest last.
     *
     * @return array<int, MailMessage>
     */
    public function unreadFromSender(int $limit = 25): array
    {
        $config = $this->settings->imapConfig();
        $stream = $this->open($config);

        try {
            // UNSEEN + FROM narrows server-side so we never pull the whole
            // inbox; Gmail treats FROM as a substring match on the header.
            $criteria = 'UNSEEN';

            if ($config['sender'] !== '') {
                $criteria .= ' FROM "'.str_replace('"', '', $config['sender']).'"';
            }

            $ids = imap_search($stream, $criteria, SE_UID);

            if ($ids === false) {
                return [];
            }

            $ids = array_slice($ids, -$limit);
            $messages = [];

            foreach ($ids as $uid) {
                $messages[] = $this->read($stream, (int) $uid);
            }

            return $messages;
        } finally {
            imap_close($stream);
        }
    }

    /**
     * Most recent messages regardless of read state — used by the
     * inspector to learn the real structure before a parser exists.
     *
     * @return array<int, MailMessage>
     */
    public function recentFromSender(int $limit = 3): array
    {
        $config = $this->settings->imapConfig();
        $stream = $this->open($config);

        try {
            $criteria = $config['sender'] !== ''
                ? 'FROM "'.str_replace('"', '', $config['sender']).'"'
                : 'ALL';

            $ids = imap_search($stream, $criteria, SE_UID);

            if ($ids === false) {
                return [];
            }

            $messages = [];

            foreach (array_slice($ids, -$limit) as $uid) {
                $messages[] = $this->read($stream, (int) $uid);
            }

            return $messages;
        } finally {
            imap_close($stream);
        }
    }

    public function markRead(string $uid): void
    {
        $stream = $this->open($this->settings->imapConfig());

        try {
            imap_setflag_full($stream, $uid, '\\Seen', ST_UID);
        } finally {
            imap_close($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return resource|\IMAP\Connection
     */
    protected function open(array $config)
    {
        if (! function_exists('imap_open')) {
            throw new MailboxException('The PHP imap extension is not available on this host.');
        }

        if ($config['address'] === '' || $config['password'] === '') {
            throw new MailboxException('Gmail address or App Password is not set — add them in Settings → Vollna.');
        }

        $mailbox = sprintf('{%s:%d/imap/ssl}%s', $config['host'], $config['port'], $config['folder']);

        // Suppressed because imap_open emits warnings for auth failures on
        // top of returning false; the real reason comes from imap_last_error.
        $stream = @imap_open($mailbox, $config['address'], $config['password'], 0, 1);

        if ($stream === false) {
            // imap_last_error can echo the attempted credentials on some
            // servers, so strip anything resembling the password first.
            $error = (string) imap_last_error();
            $error = str_replace($config['password'], '[redacted]', $error);

            throw new MailboxException('IMAP connection failed: '.($error !== '' ? $error : 'unknown error'));
        }

        return $stream;
    }

    /**
     * @param  resource|\IMAP\Connection  $stream
     */
    protected function read($stream, int $uid): MailMessage
    {
        $header = imap_headerinfo($stream, imap_msgno($stream, $uid));
        $structure = imap_fetchstructure($stream, $uid, FT_UID);

        return new MailMessage(
            uid: (string) $uid,
            from: $this->headerAddress($header),
            subject: $this->decode((string) ($header->subject ?? '')),
            date: (string) ($header->date ?? ''),
            html: $this->body($stream, $uid, $structure, 'HTML'),
            text: $this->body($stream, $uid, $structure, 'PLAIN'),
        );
    }

    protected function headerAddress(mixed $header): string
    {
        $from = $header->from[0] ?? null;

        if (! $from) {
            return '';
        }

        return ($from->mailbox ?? '').'@'.($from->host ?? '');
    }

    /**
     * Walks the MIME tree for the first part of the requested subtype and
     * decodes it. Gmail nests Vollna's HTML under multipart/alternative.
     */
    protected function body($stream, int $uid, mixed $structure, string $subtype): string
    {
        if (! $structure) {
            return '';
        }

        // Non-multipart: the whole body is the part.
        if (empty($structure->parts)) {
            if (strtoupper((string) ($structure->subtype ?? '')) !== $subtype) {
                return '';
            }

            return $this->decodePart((string) imap_body($stream, $uid, FT_UID), (int) ($structure->encoding ?? 0));
        }

        foreach ($structure->parts as $index => $part) {
            $section = (string) ($index + 1);

            if (strtoupper((string) ($part->subtype ?? '')) === $subtype) {
                return $this->decodePart(
                    (string) imap_fetchbody($stream, $uid, $section, FT_UID),
                    (int) ($part->encoding ?? 0),
                );
            }

            // One level of nesting covers multipart/alternative inside
            // multipart/mixed, which is what Gmail delivers.
            foreach ($part->parts ?? [] as $subIndex => $subPart) {
                if (strtoupper((string) ($subPart->subtype ?? '')) === $subtype) {
                    return $this->decodePart(
                        (string) imap_fetchbody($stream, $uid, $section.'.'.($subIndex + 1), FT_UID),
                        (int) ($subPart->encoding ?? 0),
                    );
                }
            }
        }

        return '';
    }

    protected function decodePart(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw, true),
            4 => (string) quoted_printable_decode($raw),
            default => $raw,
        };
    }

    protected function decode(string $value): string
    {
        $decoded = imap_mime_header_decode($value);
        $out = '';

        foreach ($decoded as $part) {
            $out .= $part->text;
        }

        return $out !== '' ? $out : $value;
    }
}
