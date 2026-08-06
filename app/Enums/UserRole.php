<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case FrontDesk = 'front_desk';
    case Cashier = 'cashier';
    case Housekeeping = 'housekeeping';

    public static function operational(): array
    {
        return [self::Admin->value, self::FrontDesk->value, self::Cashier->value];
    }

    public static function canOperateRegister(): array
    {
        return [self::Admin->value, self::FrontDesk->value, self::Cashier->value];
    }
}
