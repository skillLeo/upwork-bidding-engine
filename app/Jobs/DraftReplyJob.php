<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsForTenant;
use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Message;
use App\Services\OpenClawService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs via dispatchSync() from ClientController::draftReply() so the "Draft
 * reply" button in Client Memory gets its answer in the same request — it's
 * still a real queueable Job (not inline controller logic) so it can also be
 * pushed onto the queue later without changing this class.
 */
class DraftReplyJob implements ShouldQueue
{
    use RunsForTenant;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $messageId)
    {
        $this->captureTenant();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(OpenClawService $openClaw): void
    {
        $this->forTenant(fn () => $this->run($openClaw));
    }

    protected function run(OpenClawService $openClaw): void
    {
        $message = Message::with('client')->find($this->messageId);

        if (! $message || ! $message->client) {
            return;
        }

        $client = $message->client;

        $history = $client->messages()
            ->where('id', '!=', $message->id)
            ->latest()
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn (Message $m) => ['direction' => $m->direction->value, 'text' => $m->text])
            ->values()
            ->all();

        try {
            $result = $openClaw->draftReply($client, $message->text, $history);
        } catch (\Throwable $e) {
            report($e);

            ActivityLog::record(ActivityType::ReplyDraftFailed, subject: $message, meta: [
                'error' => $e->getMessage(),
            ]);

            // Swallow rather than throw: this often runs inline via dispatchSync()
            // inside an HTTP request, and the inbound message is already saved —
            // leaving drafted_reply null lets the UI offer a retry instead of a 500.
            return;
        }

        $message->update([
            'drafted_reply' => $result['reply'],
            'needs_hassam' => $result['needs_hassam'],
        ]);

        ActivityLog::record(ActivityType::ReplyDrafted, subject: $message, meta: [
            'needs_hassam' => $result['needs_hassam'],
        ]);

        $this->notifyBidder($openClaw, $client, $message->text, $result);
    }

    /**
     * WhatsApp copy of the draft, so a client reply can be handled from the
     * phone without opening the dashboard. Best-effort: the draft is already
     * saved above, and a notification failure must not undo that.
     *
     * @param  array{reply: string, needs_hassam: bool, why: string}  $result
     */
    protected function notifyBidder(OpenClawService $openClaw, $client, string $incoming, array $result): void
    {
        $to = app(SettingsService::class)->bidderWhatsapp();

        if (! $to) {
            return;
        }

        $decision = $result['needs_hassam']
            ? '⚠️ NEEDS YOUR DECISION: Yes'.($result['why'] !== '' ? "\n".$result['why'] : '')
            : 'NEEDS YOUR DECISION: No — safe to paste as-is';

        $lines = [
            '💬 CLIENT REPLIED — '.($client->lead?->title ?? $client->name),
            $incoming,
            '',
            '✏️ SUGGESTED REPLY:',
            $result['reply'],
            '',
            $decision,
            '',
            'Nothing is sent automatically — copy it into Upwork yourself.',
        ];

        try {
            $openClaw->sendWhatsAppMessage($to, implode("\n", $lines));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
