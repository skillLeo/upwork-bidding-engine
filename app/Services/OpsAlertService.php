<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Best-effort operator alerts about the pipeline itself (Vollna gone
 * silent, webhook deliveries being rejected). WhatsApp first — the channel
 * that's actually watched — with admin email as the fallback for the
 * co-failure case where the Mac running OpenClaw is itself offline.
 * Never throws: an alert about a broken pipeline must not break anything
 * else, so both channels failing just returns false and the caller decides
 * whether to retry later.
 */
class OpsAlertService
{
    public function __construct(
        protected OpenClawService $openClaw,
        protected SettingsService $settings,
    ) {}

    public function send(string $message): bool
    {
        try {
            if ($to = $this->settings->bidderWhatsapp()) {
                $this->openClaw->sendWhatsAppMessage($to, $message);

                return true;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $admins = User::query()->where('role', UserRole::Admin)->pluck('email');

            if ($admins->isNotEmpty() && $this->settings->get('mail_host')) {
                Mail::raw($message, function ($mail) use ($admins) {
                    $mail->to($admins->all())->subject('SkillLeo pipeline alert');
                });

                return true;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return false;
    }
}
