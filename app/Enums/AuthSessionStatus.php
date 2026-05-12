<?php

declare(strict_types=1);

namespace App\Enums;

enum AuthSessionStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
