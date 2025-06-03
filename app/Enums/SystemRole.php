<?php

namespace App\Enums;

enum SystemRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case CONTENT_ADMIN = 'content_admin';
    case SUPPORT_ADMIN = 'support_admin';
} 