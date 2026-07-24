<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsForTenants;
use App\Services\Mail\Mailbox;
use App\Services\Mail\MailboxException;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Diagnostic only — connects to the mailbox and dumps the real structure
 * of recent Vollna emails so the parser can be written against actual
 * messages instead of assumptions. Writes nothing, imports nothing, and
 * leaves every message unread.
 */
class VollnaInspectEmailCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'vollna:inspect-email {--limit=2 : how many recent messages to dump} {--chars=3000 : body characters to show}
        {--tenant= : run for this tenant only}';

    protected $description = 'Dump the structure of recent Vollna emails (read-only diagnostic)';

    public function handle(Mailbox $mailbox, SettingsService $settings): int
    {
        return $this->forOneTenant(fn () => $this->runForTenant($mailbox, $settings));
    }

    protected function runForTenant(Mailbox $mailbox, SettingsService $settings): int
    {
        $config = $settings->imapConfig();

        $this->line('Host: '.$config['host'].':'.$config['port'].' folder '.$config['folder']);
        $this->line('Account: '.$config['address']);
        $this->line('App Password: '.($config['password'] !== '' ? 'set ('.strlen($config['password']).' chars)' : 'NOT SET'));
        $this->line('Sender filter: '.($config['sender'] !== '' ? $config['sender'] : '(none — will read any sender)'));
        $this->newLine();

        try {
            $messages = $mailbox->recentFromSender((int) $this->option('limit'));
        } catch (MailboxException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($messages === []) {
            $this->warn('Connected fine, but no messages matched the sender filter. Try --limit with a looser vollna_sender_filter in Settings.');

            return self::SUCCESS;
        }

        $chars = (int) $this->option('chars');

        foreach ($messages as $i => $message) {
            $this->line(str_repeat('=', 70));
            $this->line('MESSAGE '.($i + 1).' | uid '.$message->uid);
            $this->line('From:    '.$message->from);
            $this->line('Subject: '.$message->subject);
            $this->line('Date:    '.$message->date);
            $this->line('html part: '.strlen($message->html).' chars | text part: '.strlen($message->text).' chars');
            $this->newLine();

            if ($message->text !== '') {
                $this->line('---- TEXT BODY (first '.$chars.' chars) ----');
                $this->line(mb_substr($message->text, 0, $chars));
                $this->newLine();
            }

            if ($message->html !== '') {
                $this->line('---- HTML BODY (first '.$chars.' chars) ----');
                $this->line(mb_substr($message->html, 0, $chars));
                $this->newLine();
            }
        }

        $this->info('Read-only: every message was left unread.');

        return self::SUCCESS;
    }
}
