<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case FrontDesk = 'front_desk';
    case Housekeeping = 'housekeeping';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Roles that may perform bookings, payments, and register operations.
     *
     * @return list<string>
     */
    public static function operational(): array
    {
        return [self::Admin->value, self::FrontDesk->value];
    }

    /**
     * @return list<string>
     */
    public static function canOperateRegister(): array
    {
        return self::operational();
    }

    /**
     * @return list<string>
     */
    public static function notified(): array
    {
        return [self::Admin->value, self::FrontDesk->value, self::Housekeeping->value];
    }

    public static function allowsOperational(?string $role): bool
    {
        return $role !== null && in_array($role, self::operational(), true);
    }

    public static function isDeskStaff(?string $role): bool
    {
        return $role === self::FrontDesk->value;
    }

    public static function canReceiveNotifications(?string $role): bool
    {
        return $role !== null && in_array($role, self::notified(), true);
    }

    public static function isHousekeeping(?string $role): bool
    {
        return $role === self::Housekeeping->value;
    }
}
