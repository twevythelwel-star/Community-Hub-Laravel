<?php

namespace App\Enums;

/**
 * Mirrors the `UserRole` union from src/types/index.ts.
 */
enum UserRole: string
{
    case SystemAdmin = 'System Admin';
    case Admin = 'Admin';
    case Homeowner = 'Homeowner';
    case TemporaryHomeowner = 'Temporary Homeowner';
    case Security = 'Security';
    case Staff = 'Staff';

    /** Roles with platform-wide administrative authority. */
    public function isAdministrative(): bool
    {
        return in_array($this, [self::SystemAdmin, self::Admin], true);
    }

    /** The gatehouse's role: scans passes and works the security desk. */
    public function isSecurity(): bool
    {
        return $this === self::Security;
    }

    /** Maintenance and operations staff. */
    public function isStaff(): bool
    {
        return $this === self::Staff;
    }

    /** Operational roles: Security and Staff. */
    public function isOperational(): bool
    {
        return in_array($this, [self::Security, self::Staff], true);
    }

    /** Roles that occupy a property (own or lease). */
    public function isResident(): bool
    {
        return in_array($this, [self::Homeowner, self::TemporaryHomeowner], true);
    }

    /** Maps a role onto its gate-pass category. */
    public function passCategory(): PassCategory
    {
        return match ($this) {
            self::SystemAdmin => PassCategory::SysAdmin,
            self::Admin => PassCategory::Admin,
            self::Homeowner => PassCategory::Homeowner,
            self::TemporaryHomeowner => PassCategory::Renter,
            self::Security => PassCategory::Security,
            self::Staff => PassCategory::Staff,
        };
    }

    public function label(): string
    {
        return $this->value;
    }
}
