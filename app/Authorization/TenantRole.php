<?php

namespace App\Authorization;

/**
 * The four base tenant roles and their DEFAULT permission grants.
 *
 * Since the editable-permissions decision (2026-07-24) these defaults apply
 * only when a role is first created for a workspace. After that the grants
 * live in the database, edited through the Roles matrix by anyone holding
 * permissions.edit, and are never overwritten by a deploy.
 *
 * The one exception is Owner: ALWAYS every permission, locked, re-synced on
 * every provision. That lock is the guard rail that makes a workspace
 * impossible to brick — however badly the other roles are misconfigured,
 * the Owner can always reach the permissions screen and repair them.
 */
enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Bidder = 'bidder';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Bidder => 'Bidder',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full control, always. Locked — cannot be edited or removed.',
            self::Admin => 'Everything except billing, by default. Editable.',
            self::Bidder => 'Works the pipeline and tunes the non-secret rules, by default. Editable.',
            self::Viewer => 'Read-only, by default. Editable.',
        };
    }

    /**
     * The DEFAULT grants for a freshly-created role. Not the live state —
     * that is whatever the workspace has edited it to since.
     *
     * @return array<int, string>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner => Permissions::all(),

            self::Admin => array_values(array_filter(
                Permissions::all(),
                fn (string $p) => $p !== Permissions::BILLING_MANAGE,
            )),

            // The pipeline, every AI feature, and the non-secret settings
            // keys (rules, thresholds, toggles) — but no secret key, no
            // member management, no permission editing.
            self::Bidder => [
                Permissions::LEADS_VIEW, Permissions::LEADS_UPDATE_STATUS,
                Permissions::LEADS_SET_OUTCOME, Permissions::LEADS_SET_CLIENT_VIEW,
                Permissions::LEADS_FAVORITE, Permissions::LEADS_BULK,
                Permissions::LEADS_RESCORE, Permissions::LEADS_AI_SEARCH,
                Permissions::PROPOSALS_VIEW, Permissions::PROPOSALS_EDIT_MANUAL,
                Permissions::PROPOSALS_AI_EDIT, Permissions::PROPOSALS_AI_REWRITE,
                Permissions::PROPOSALS_VERSIONS_VIEW,
                Permissions::CLIENTS_VIEW, Permissions::CLIENTS_MESSAGE,
                Permissions::CLIENTS_AI_DRAFT_REPLY,
                Permissions::ANALYTICS_VIEW, Permissions::DIAGNOSTICS_VIEW,
                Permissions::SETTINGS_VIEW,
                Permissions::NOTIFICATIONS_VIEW,
                ...Permissions::nonSecretSettingKeys(),
            ],

            self::Viewer => [
                Permissions::LEADS_VIEW,
                Permissions::PROPOSALS_VIEW, Permissions::PROPOSALS_VERSIONS_VIEW,
                Permissions::CLIENTS_VIEW,
                Permissions::ANALYTICS_VIEW, Permissions::DIAGNOSTICS_VIEW,
                Permissions::SETTINGS_VIEW,
                Permissions::NOTIFICATIONS_VIEW,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
