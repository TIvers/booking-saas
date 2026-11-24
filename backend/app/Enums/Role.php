<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';
    case Customer = 'customer';

    public function canManageTenant(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }
}
