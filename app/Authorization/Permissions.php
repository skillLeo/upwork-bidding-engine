<?php

namespace App\Authorization;

/**
 * The permission vocabulary — exactly this list, no more.
 *
 * Granularity nobody uses is complexity everybody pays for, so this is
 * deliberately small enough to fit on one screen (the read-only Roles
 * matrix). The one split that carries its weight is
 * settings.edit_rules vs settings.edit_secrets: a trusted bidder can tune
 * the budget floor without ever being able to read the Anthropic key.
 */
final class Permissions
{
    public const LEADS_VIEW = 'leads.view';

    public const LEADS_UPDATE_STATUS = 'leads.update_status';

    public const LEADS_RESCORE = 'leads.rescore';

    public const LEADS_DELETE = 'leads.delete';

    public const PROPOSALS_VIEW = 'proposals.view';

    public const PROPOSALS_EDIT = 'proposals.edit';

    public const PROPOSALS_REWRITE = 'proposals.rewrite';

    public const CLIENTS_VIEW = 'clients.view';

    public const CLIENTS_MESSAGE = 'clients.message';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_EDIT_RULES = 'settings.edit_rules';

    public const SETTINGS_EDIT_SECRETS = 'settings.edit_secrets';

    public const ANALYTICS_VIEW = 'analytics.view';

    public const BILLING_MANAGE = 'billing.manage';

    public const MEMBERS_INVITE = 'members.invite';

    public const MEMBERS_REMOVE = 'members.remove';

    public const MEMBERS_ASSIGN_ROLE = 'members.assign_role';

    /**
     * Every permission, in the order they appear on the matrix.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::LEADS_VIEW, self::LEADS_UPDATE_STATUS, self::LEADS_RESCORE, self::LEADS_DELETE,
            self::PROPOSALS_VIEW, self::PROPOSALS_EDIT, self::PROPOSALS_REWRITE,
            self::CLIENTS_VIEW, self::CLIENTS_MESSAGE,
            self::SETTINGS_VIEW, self::SETTINGS_EDIT_RULES, self::SETTINGS_EDIT_SECRETS,
            self::ANALYTICS_VIEW, self::BILLING_MANAGE,
            self::MEMBERS_INVITE, self::MEMBERS_REMOVE, self::MEMBERS_ASSIGN_ROLE,
        ];
    }
}
