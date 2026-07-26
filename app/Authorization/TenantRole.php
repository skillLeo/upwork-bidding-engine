<?php

namespace App\Authorization;

/**
 * The three tenant roles and their DEFAULT permission grants.
 *
 * THREE, NOT FOUR (P8). The 'admin' role was removed when the product
 * hierarchy was finalised: a workspace has exactly one owner who runs it,
 * and everyone else is a bidder or a viewer. What earlier drafts called an
 * "admin" is the OWNER of their own workspace — a separate workspace with
 * its own stacks, budgets, filters and members — not a second-in-command
 * inside someone else's. There is no role between owner and bidder, and
 * adding one back would immediately raise "can an admin invite an admin?",
 * which is the ambiguity this hierarchy exists to remove.
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
    case Bidder = 'bidder';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Bidder => 'Bidder',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Runs this workspace. Full control, always. Locked — cannot be edited, removed, or invited.',
            self::Bidder => 'Works the pipeline and tunes the non-secret rules, by default. Editable.',
            self::Viewer => 'Read-only, by default. Editable.',
        };
    }

    /**
     * The roles a workspace owner may hand out — the only two that can be
     * invited or assigned inside a workspace. Owner is absent by design: it
     * exists from workspace creation or by transfer, never by invitation.
     *
     * @return array<int, self>
     */
    public static function invitable(): array
    {
        return [self::Bidder, self::Viewer];
    }

    /**
     * @return array<int, string>
     */
    public static function invitableValues(): array
    {
        return array_map(fn (self $role) => $role->value, self::invitable());
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
