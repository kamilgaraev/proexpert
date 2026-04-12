<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Support;

final class BrigadeStatuses
{
    public const PROFILE_DRAFT = 'draft';
    public const PROFILE_PENDING_REVIEW = 'pending_review';
    public const PROFILE_APPROVED = 'approved';
    public const PROFILE_REJECTED = 'rejected';
    public const PROFILE_SUSPENDED = 'suspended';

    public const AVAILABILITY_AVAILABLE = 'available';
    public const AVAILABILITY_PARTIALLY_AVAILABLE = 'partially_available';
    public const AVAILABILITY_BUSY = 'busy';

    public const INVITATION_PENDING = 'pending';
    public const INVITATION_ACCEPTED = 'accepted';
    public const INVITATION_DECLINED = 'declined';
    public const INVITATION_CANCELLED = 'cancelled';
    public const INVITATION_EXPIRED = 'expired';

    public const REQUEST_OPEN = 'open';
    public const REQUEST_IN_REVIEW = 'in_review';
    public const REQUEST_CLOSED = 'closed';
    public const REQUEST_CANCELLED = 'cancelled';

    public const RESPONSE_PENDING = 'pending';
    public const RESPONSE_APPROVED = 'approved';
    public const RESPONSE_REJECTED = 'rejected';

    public const ASSIGNMENT_PLANNED = 'planned';
    public const ASSIGNMENT_ACTIVE = 'active';
    public const ASSIGNMENT_PAUSED = 'paused';
    public const ASSIGNMENT_COMPLETED = 'completed';
    public const ASSIGNMENT_CANCELLED = 'cancelled';

    public const DOCUMENT_PENDING = 'pending';
    public const DOCUMENT_APPROVED = 'approved';
    public const DOCUMENT_REJECTED = 'rejected';

    public static function profileStatuses(): array
    {
        return [
            self::PROFILE_DRAFT,
            self::PROFILE_PENDING_REVIEW,
            self::PROFILE_APPROVED,
            self::PROFILE_REJECTED,
            self::PROFILE_SUSPENDED,
        ];
    }

    public static function availabilityStatuses(): array
    {
        return [
            self::AVAILABILITY_AVAILABLE,
            self::AVAILABILITY_PARTIALLY_AVAILABLE,
            self::AVAILABILITY_BUSY,
        ];
    }

    public static function invitationStatuses(): array
    {
        return [
            self::INVITATION_PENDING,
            self::INVITATION_ACCEPTED,
            self::INVITATION_DECLINED,
            self::INVITATION_CANCELLED,
            self::INVITATION_EXPIRED,
        ];
    }

    public static function requestStatuses(): array
    {
        return [
            self::REQUEST_OPEN,
            self::REQUEST_IN_REVIEW,
            self::REQUEST_CLOSED,
            self::REQUEST_CANCELLED,
        ];
    }

    public static function responseStatuses(): array
    {
        return [
            self::RESPONSE_PENDING,
            self::RESPONSE_APPROVED,
            self::RESPONSE_REJECTED,
        ];
    }

    public static function assignmentStatuses(): array
    {
        return [
            self::ASSIGNMENT_PLANNED,
            self::ASSIGNMENT_ACTIVE,
            self::ASSIGNMENT_PAUSED,
            self::ASSIGNMENT_COMPLETED,
            self::ASSIGNMENT_CANCELLED,
        ];
    }

    public static function documentStatuses(): array
    {
        return [
            self::DOCUMENT_PENDING,
            self::DOCUMENT_APPROVED,
            self::DOCUMENT_REJECTED,
        ];
    }
}
