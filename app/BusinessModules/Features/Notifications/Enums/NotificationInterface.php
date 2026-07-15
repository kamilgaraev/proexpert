<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Enums;

enum NotificationInterface: string
{
    case Admin = 'admin';
    case Lk = 'lk';
    case Mobile = 'mobile';
    case Customer = 'customer';
}
