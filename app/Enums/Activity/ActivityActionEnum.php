<?php

declare(strict_types=1);

namespace App\Enums\Activity;

enum ActivityActionEnum: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Viewed = 'viewed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Assigned = 'assigned';
    case Revoked = 'revoked';
    case Exported = 'exported';
    case Login = 'login';
    case Logout = 'logout';
}
