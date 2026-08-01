<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Cursors;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSubscriptionCursorCodec;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportExecutionClock;

final class SignedReportSubscriptionCursorCodecTest extends TestCase
{
    private DateTimeImmutable $now;

    private FakeReportExecutionClock $clock;

    private SignedReportSubscriptionCursorCodec $codec;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-07-26T09:00:00+00:00');
        $this->clock = new FakeReportExecutionClock($this->now);
        $this->codec = new SignedReportSubscriptionCursorCodec('subscription-cursor-test-key', $this->clock);
    }

    public function test_rejects_expired_cursor(): void
    {
        $token = $this->token(expiresAt: new DateTimeImmutable('2026-07-26T09:00:00+00:00'));

        $this->expectCursorFailure(fn (): ReportSubscriptionCursor => $this->decode($token));
    }

    public function test_rejects_cursor_for_another_organization(): void
    {
        $token = $this->token();

        $this->expectCursorFailure(fn (): ReportSubscriptionCursor => $this->codec->decode(
            $this->context(organizationId: 2),
            ReportSubscriptionStatus::ACTIVE,
            $token,
        ));
    }

    public function test_rejects_cursor_for_another_owner(): void
    {
        $token = $this->token();

        $this->expectCursorFailure(fn (): ReportSubscriptionCursor => $this->codec->decode(
            $this->context(ownerId: 2),
            ReportSubscriptionStatus::ACTIVE,
            $token,
        ));
    }

    public function test_rejects_cursor_for_another_status_filter(): void
    {
        $token = $this->token();

        $this->expectCursorFailure(fn (): ReportSubscriptionCursor => $this->codec->decode(
            $this->context(),
            ReportSubscriptionStatus::PAUSED,
            $token,
        ));
    }

    public function test_rejects_cursor_with_invalid_signature(): void
    {
        $token = $this->token();
        $tampered = substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');

        $this->expectCursorFailure(fn (): ReportSubscriptionCursor => $this->decode($tampered));
    }

    private function token(?DateTimeImmutable $expiresAt = null): string
    {
        return $this->codec->encode(
            $this->context(),
            new ReportSubscriptionCursor(
                ReportSubscriptionCursor::VERSION,
                1,
                1,
                ReportSubscriptionStatus::ACTIVE,
                ReportSubscriptionCursor::ORDER,
                new DateTimeImmutable('2026-07-26T08:00:00+00:00'),
                '01J3R5QZ6H7K8M9N0P1Q2R3S4T',
                $expiresAt ?? new DateTimeImmutable('2026-07-26T09:15:00+00:00'),
            ),
        );
    }

    private function decode(string $token): ReportSubscriptionCursor
    {
        return $this->codec->decode($this->context(), ReportSubscriptionStatus::ACTIVE, $token);
    }

    private function context(int $organizationId = 1, int $ownerId = 1): ReportExecutionContext
    {
        $timezone = new DateTimeZone('UTC');

        return new ReportExecutionContext(
            new ReportActor($ownerId, 'active', ['reports.view']),
            new ReportScope($organizationId, [$organizationId], [], [], $timezone),
            new ReportVisibility(true, true, true, true, false, false, false),
            new AuthorizationDecisionContext(
                'http',
                $organizationId,
                [$organizationId],
                [],
                [],
                $timezone,
                'subscription-cursor-test',
                null,
            ),
        );
    }

    private function expectCursorFailure(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected subscription cursor to be rejected.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_CURSOR_INVALID, $exception->errorCode);
            self::assertSame(['fields' => ['cursor']], $exception->safeFields);
        }
    }
}
