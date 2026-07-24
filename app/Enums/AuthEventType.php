<?php

namespace App\Enums;

/**
 * Every authentication-relevant thing that can happen to an account.
 *
 * The later values (invite_accepted, role_changed, impersonation_*) have no
 * writer yet — those flows arrive in P4 and P5. They are declared now so the
 * vocabulary is fixed once and the audit table never needs a migration to
 * learn a new word.
 */
enum AuthEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordChanged = 'password_changed';
    case TwoFactorEnabled = 'twofa_enabled';
    case TwoFactorDisabled = 'twofa_disabled';
    case TokenRevoked = 'token_revoked';
    case InviteAccepted = 'invite_accepted';
    case RoleChanged = 'role_changed';
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationEnded = 'impersonation_ended';

    public function label(): string
    {
        return match ($this) {
            self::LoginSuccess => 'Signed in',
            self::LoginFailed => 'Failed sign-in',
            self::Logout => 'Signed out',
            self::PasswordChanged => 'Password changed',
            self::TwoFactorEnabled => 'Two-factor enabled',
            self::TwoFactorDisabled => 'Two-factor disabled',
            self::TokenRevoked => 'Session revoked',
            self::InviteAccepted => 'Invitation accepted',
            self::RoleChanged => 'Role changed',
            self::ImpersonationStarted => 'Impersonation started',
            self::ImpersonationEnded => 'Impersonation ended',
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
