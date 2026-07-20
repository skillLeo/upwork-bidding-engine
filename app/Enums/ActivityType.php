<?php

namespace App\Enums;

/**
 * Not DB-enforced (activity_logs.type is a free-text string so new event
 * types never require a migration) — this is the canonical list of values
 * the app itself writes, used by services/jobs instead of raw strings.
 */
enum ActivityType: string
{
    case LeadReceived = 'lead_received';
    case LeadDuplicateSkipped = 'lead_duplicate_skipped';
    case LeadPruned = 'lead_pruned';
    case LeadResurrectionBlocked = 'lead_resurrection_blocked';
    case LeadFiltered = 'lead_filtered';
    case LeadScored = 'lead_scored';
    case LeadScoringFailed = 'lead_scoring_failed';
    case LeadStatusUpdated = 'lead_status_updated';
    case ProposalSent = 'proposal_sent';
    case BidderNotified = 'bidder_notified';
    case BidderNotifyFailed = 'bidder_notify_failed';
    case FollowUpSent = 'follow_up_sent';
    case ReplyReceived = 'reply_received';
    case ReplyDrafted = 'reply_drafted';
    case ReplyDraftFailed = 'reply_draft_failed';
    case SettingUpdated = 'setting_updated';
    case ConnectionTested = 'connection_tested';
    case WebhookRejected = 'webhook_rejected';
    case VollnaSilenceAlert = 'vollna_silence_alert';
    case VollnaRejectedAlert = 'vollna_rejected_alert';
    case UserLoggedIn = 'user_logged_in';
}
