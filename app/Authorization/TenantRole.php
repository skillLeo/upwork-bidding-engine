<?php

namespace App\Authorization;

/**
 * The four fixed tenant roles and the exact permissions each carries.
 *
 * Fixed, not customer-editable: a role BUILDER is a week of work that
 * produces a screen customers open twice, and it becomes a paid feature the
 * day someone actually asks. The Roles matrix in the UI renders this map
 * read-only so customers can SEE what each role can do without being able to
 * change it.
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
            self::Owner => 'Full control, including billing and deleting the workspace.',
            self::Admin => 'Everything except billing — settings, API keys, and members.',
            self::Bidder => 'Works the pipeline: leads, proposals, clients, status changes. No API keys, no billing.',
            self::Viewer => 'Read-only. For a manager watching output.',
        };
    }

    /**
     * The permissions granted to this role.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Owner and admin get everything the tenant has. Owner's extra
            // powers (transfer/delete workspace, be the sole owner) are
            // enforced by dedicated checks, not a permission string, because
            // "there must always be exactly one owner" is an invariant a flat
            // permission cannot express.
            self::Owner => Permissions::all(),

            self::Admin => array_values(array_filter(
                Permissions::all(),
                fn (string $p) => $p !== Permissions::BILLING_MANAGE,
            )),

            // The pipeline, no secrets, no billing, no member management.
            // settings.view + edit_rules but NOT edit_secrets is the whole
            // point of splitting those two permissions.
            self::Bidder => [
                Permissions::LEADS_VIEW, Permissions::LEADS_UPDATE_STATUS, Permissions::LEADS_RESCORE,
                Permissions::PROPOSALS_VIEW, Permissions::PROPOSALS_EDIT, Permissions::PROPOSALS_REWRITE,
                Permissions::CLIENTS_VIEW, Permissions::CLIENTS_MESSAGE,
                Permissions::SETTINGS_VIEW, Permissions::SETTINGS_EDIT_RULES,
                Permissions::ANALYTICS_VIEW,
            ],

            // Sees everything, changes nothing.
            self::Viewer => [
                Permissions::LEADS_VIEW,
                Permissions::PROPOSALS_VIEW,
                Permissions::CLIENTS_VIEW,
                Permissions::SETTINGS_VIEW,
                Permissions::ANALYTICS_VIEW,
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
