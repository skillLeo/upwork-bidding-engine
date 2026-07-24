<?php

namespace App\Authorization;

use App\Services\SettingsService;

/**
 * The permission vocabulary — ultra-granular BY EXPLICIT PRODUCT DECISION
 * (2026-07-24, reversing the earlier "17 permissions, read-only matrix"
 * spec): one permission per feature, and one per settings key, so an admin
 * can compose exactly what each role — or one specific user — may touch.
 *
 * Two halves:
 *
 *   FEATURES — hand-defined, one per real capability (below).
 *
 *   SETTING KEYS — DERIVED from SettingsService::SCHEMA as
 *   "setting.{key}". Derived, not hand-listed, so a settings key added next
 *   month automatically gets its own permission with zero maintenance here.
 *   Holding setting.{key} means: may WRITE that key, and for secret keys,
 *   may SEE its masked entry at all (absent otherwise).
 */
final class Permissions
{
    // ------------------------------------------------------------- leads
    public const LEADS_VIEW = 'leads.view';

    public const LEADS_UPDATE_STATUS = 'leads.update_status';

    public const LEADS_SET_OUTCOME = 'leads.set_outcome';

    public const LEADS_SET_CLIENT_VIEW = 'leads.set_client_view';

    public const LEADS_FAVORITE = 'leads.favorite';

    public const LEADS_BULK = 'leads.bulk';

    public const LEADS_RESCORE = 'leads.rescore';

    public const LEADS_DELETE = 'leads.delete';

    public const LEADS_SYNC_VOLLNA = 'leads.sync_vollna';

    public const LEADS_AI_SEARCH = 'leads.ai_search';

    // --------------------------------------------------------- proposals
    public const PROPOSALS_VIEW = 'proposals.view';

    public const PROPOSALS_EDIT_MANUAL = 'proposals.edit_manual';

    public const PROPOSALS_AI_EDIT = 'proposals.ai_edit';

    public const PROPOSALS_AI_REWRITE = 'proposals.ai_rewrite';

    public const PROPOSALS_VERSIONS_VIEW = 'proposals.versions_view';

    // ----------------------------------------------------------- clients
    public const CLIENTS_VIEW = 'clients.view';

    public const CLIENTS_MESSAGE = 'clients.message';

    public const CLIENTS_AI_DRAFT_REPLY = 'clients.ai_draft_reply';

    // ------------------------------------------------- analytics & health
    public const ANALYTICS_VIEW = 'analytics.view';

    public const AI_USAGE_VIEW = 'ai_usage.view';

    public const DIAGNOSTICS_VIEW = 'diagnostics.view';

    // ---------------------------------------------------------- settings
    /** See the Settings page at all (non-secret values). */
    public const SETTINGS_VIEW = 'settings.view';

    /** Run the per-service connection tests. */
    public const SETTINGS_TEST_CONNECTIONS = 'settings.test_connections';

    // ----------------------------------------------------------- members
    public const MEMBERS_INVITE = 'members.invite';

    public const MEMBERS_REMOVE = 'members.remove';

    public const MEMBERS_ASSIGN_ROLE = 'members.assign_role';

    /**
     * Edit what roles/users can do — including this permission itself.
     * Owner is untouchable regardless; everything else is fair game for a
     * holder of this. That self-referential freedom is the product decision.
     */
    public const PERMISSIONS_EDIT = 'permissions.edit';

    // ------------------------------------------------------------ billing
    public const BILLING_MANAGE = 'billing.manage';

    // ------------------------------------------------------ notifications
    public const NOTIFICATIONS_VIEW = 'notifications.view';

    // ---------------------------------------------------------- workspace
    /**
     * The Workspace tab: name/slug, plan/status (read-only there), member
     * count, export. Transfer-ownership and delete-workspace are NOT gated
     * by this (or any delegable permission) — they are hardcoded to the
     * tenant's owner_user_id, the same lock pattern as the rest of P4.
     */
    public const WORKSPACE_MANAGE = 'workspace.manage';

    /**
     * The hand-defined feature permissions, in display order.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            self::LEADS_VIEW, self::LEADS_UPDATE_STATUS, self::LEADS_SET_OUTCOME,
            self::LEADS_SET_CLIENT_VIEW, self::LEADS_FAVORITE, self::LEADS_BULK,
            self::LEADS_RESCORE, self::LEADS_DELETE, self::LEADS_SYNC_VOLLNA, self::LEADS_AI_SEARCH,
            self::PROPOSALS_VIEW, self::PROPOSALS_EDIT_MANUAL, self::PROPOSALS_AI_EDIT,
            self::PROPOSALS_AI_REWRITE, self::PROPOSALS_VERSIONS_VIEW,
            self::CLIENTS_VIEW, self::CLIENTS_MESSAGE, self::CLIENTS_AI_DRAFT_REPLY,
            self::ANALYTICS_VIEW, self::AI_USAGE_VIEW, self::DIAGNOSTICS_VIEW,
            self::SETTINGS_VIEW, self::SETTINGS_TEST_CONNECTIONS,
            self::MEMBERS_INVITE, self::MEMBERS_REMOVE, self::MEMBERS_ASSIGN_ROLE,
            self::PERMISSIONS_EDIT,
            self::BILLING_MANAGE,
            self::NOTIFICATIONS_VIEW,
            self::WORKSPACE_MANAGE,
        ];
    }

    /**
     * One permission per settings key, derived from the schema.
     *
     * @return array<int, string>
     */
    public static function settingKeys(): array
    {
        return array_map(
            fn (string $key) => self::forSettingKey($key),
            array_keys(SettingsService::SCHEMA),
        );
    }

    public static function forSettingKey(string $key): string
    {
        return 'setting.'.$key;
    }

    /**
     * Every permission that exists.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [...self::features(), ...self::settingKeys()];
    }

    /**
     * The setting.{key} permissions for keys whose SCHEMA entry is NOT
     * secret — the safe-to-delegate half (rules, thresholds, toggles).
     *
     * @return array<int, string>
     */
    public static function nonSecretSettingKeys(): array
    {
        return array_values(array_map(
            fn (string $key) => self::forSettingKey($key),
            array_keys(array_filter(SettingsService::SCHEMA, fn (array $meta) => ! $meta['secret'])),
        ));
    }
}
