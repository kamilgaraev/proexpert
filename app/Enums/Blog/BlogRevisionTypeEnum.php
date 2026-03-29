<?php

declare(strict_types=1);

namespace App\Enums\Blog;

enum BlogRevisionTypeEnum: string
{
    case MANUAL = 'manual';
    case AUTOSAVE = 'autosave';
    case PUBLISH = 'publish';
    case RESTORE = 'restore';
}
