<?php

namespace App\Authorization;

/**
 * Platform staff roles — Hassam and anyone he hires, across ALL tenants.
 *
 * Stored as a plain column on users (see the add_rbac_columns migration),
 * in a namespace completely separate from the tenant roles above. Seeded in
 * code, never editable through any tenant-facing UI: a customer-editable
 * permission system that could grant platform access is a privilege
 * escalation hole, full stop.
 *
 * The platform console these gate is P5 — this enum ships now so the column
 * has a defined vocabulary and the two systems (platform vs tenant) never
 * share a string.
 */
enum PlatformRole: string
{
    case Owner = 'platform_owner';
    case Support = 'platform_support';
    case Billing = 'platform_billing';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Platform owner',
            self::Support => 'Platform support',
            self::Billing => 'Platform billing',
        };
    }

    /**
     * May this platform role read tenant secrets and proposal/message
     * content? Only the owner. Support gets metadata and health but is
     * explicitly walled off from API keys, proposal text and message bodies
     * (enforced in P5's console queries); billing sees no lead data at all.
     */
    public function canReadTenantContent(): bool
    {
        return $this === self::Owner;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
