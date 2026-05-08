<?php

declare(strict_types=1);

namespace App\Enums\Activity;

enum ActivityResultEnum: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Warning = 'warning';
}
