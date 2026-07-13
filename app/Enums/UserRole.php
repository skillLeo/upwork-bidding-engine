<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Bidder = 'bidder';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Bidder => 'Bidder',
        };
    }
}
