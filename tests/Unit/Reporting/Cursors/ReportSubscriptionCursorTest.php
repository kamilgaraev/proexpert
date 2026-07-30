<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Cursors;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionCursor;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSubscriptionCursorTest extends TestCase
{
    public function test_accepts_canonical_cursor_scope_and_utc_timestamps(): void
    {
        $cursor = new ReportSubscriptionCursor(
            1,
            10,
            20,
            ReportSubscriptionStatus::ACTIVE,
            ReportSubscriptionCursor::ORDER,
            new DateTimeImmutable('2026-07-26T09:00:00+00:00'),
            '01J3R5QZ6H7K8M9N0P1Q2R3S4T',
            new DateTimeImmutable('2026-07-26T09:15:00+00:00'),
        );

        self::assertSame(ReportSubscriptionCursor::VERSION, $cursor->version);
        self::assertSame(ReportSubscriptionCursor::ORDER, $cursor->order);
    }

    #[DataProvider('invalidVersionAndScope')]
    public function test_rejects_invalid_version_or_scope(int $version, int $organizationId, int $ownerId): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cursor(version: $version, organizationId: $organizationId, ownerId: $ownerId);
    }

    public static function invalidVersionAndScope(): iterable
    {
        yield 'unsupported version' => [2, 10, 20];
        yield 'zero organization' => [1, 0, 20];
        yield 'zero owner' => [1, 10, 0];
    }

    public function test_rejects_deleted_status_filter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cursor(statusFilter: ReportSubscriptionStatus::DELETED);
    }

    public function test_rejects_noncanonical_order(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cursor(order: 'id_asc');
    }

    #[DataProvider('malformedLastIds')]
    public function test_rejects_malformed_or_lowercase_last_id(string $lastId): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cursor(lastId: $lastId);
    }

    public static function malformedLastIds(): iterable
    {
        yield 'too short' => ['01J3R5QZ6H7K8M9N0P1Q2R3S4'];
        yield 'lowercase' => ['01j3r5qz6h7k8m9n0p1q2r3s4t'];
        yield 'forbidden I' => ['01J3R5QZ6H7K8M9N0P1Q2R3S4I'];
    }

    #[DataProvider('nonUtcTimestamps')]
    public function test_rejects_non_utc_timestamps(?DateTimeImmutable $lastNextRunAt, DateTimeImmutable $expiresAt): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cursor(lastNextRunAt: $lastNextRunAt, expiresAt: $expiresAt);
    }

    public static function nonUtcTimestamps(): iterable
    {
        yield 'last next run at' => [
            new DateTimeImmutable('2026-07-26T12:00:00+03:00'),
            new DateTimeImmutable('2026-07-26T09:15:00+00:00'),
        ];
        yield 'expiration' => [
            new DateTimeImmutable('2026-07-26T09:00:00+00:00'),
            new DateTimeImmutable('2026-07-26T12:15:00+03:00'),
        ];
    }

    private function cursor(
        int $version = ReportSubscriptionCursor::VERSION,
        int $organizationId = 10,
        int $ownerId = 20,
        ?ReportSubscriptionStatus $statusFilter = ReportSubscriptionStatus::ACTIVE,
        string $order = ReportSubscriptionCursor::ORDER,
        ?DateTimeImmutable $lastNextRunAt = null,
        string $lastId = '01J3R5QZ6H7K8M9N0P1Q2R3S4T',
        ?DateTimeImmutable $expiresAt = null,
    ): ReportSubscriptionCursor {
        return new ReportSubscriptionCursor(
            $version,
            $organizationId,
            $ownerId,
            $statusFilter,
            $order,
            $lastNextRunAt ?? new DateTimeImmutable('2026-07-26T09:00:00+00:00'),
            $lastId,
            $expiresAt ?? new DateTimeImmutable('2026-07-26T09:15:00+00:00'),
        );
    }
}
