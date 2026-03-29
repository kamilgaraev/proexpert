<?php

declare(strict_types=1);

namespace App\Enums\Blog;

enum BlogContextEnum: string
{
    case MARKETING = 'marketing';
    case HOLDING = 'holding';
}
