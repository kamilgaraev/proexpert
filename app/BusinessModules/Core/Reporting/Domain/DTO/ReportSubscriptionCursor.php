<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportSubscriptionCursor
{
    public const VERSION = 1;

    public const ORDER = 'next_run_at_asc_nulls_last__id_asc';

    public function __construct(public int $version, public int $organizationId, public int $ownerId, public ?ReportSubscriptionStatus $statusFilter, public string $order, public ?DateTimeImmutable $lastNextRunAt, public string $lastId, public DateTimeImmutable $expiresAt)
    {
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException('subscription_cursor_version_invalid');
        }
        if ($organizationId < 1 || $ownerId < 1) {
            throw new InvalidArgumentException('subscription_cursor_scope_invalid');
        }
        if ($statusFilter === ReportSubscriptionStatus::DELETED) {
            throw new InvalidArgumentException('subscription_cursor_filter_invalid');
        }
        if ($order !== self::ORDER) {
            throw new InvalidArgumentException('subscription_cursor_order_invalid');
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $lastId) !== 1) {
            throw new InvalidArgumentException('subscription_cursor_last_id_invalid');
        }
        if (($lastNextRunAt !== null && $lastNextRunAt->getOffset() !== 0) || $expiresAt->getOffset() !== 0) {
            throw new InvalidArgumentException('subscription_cursor_timestamp_invalid');
        }
    }
}
