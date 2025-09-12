<?php

namespace App\Enums\UserInvitation;

enum InvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
}
