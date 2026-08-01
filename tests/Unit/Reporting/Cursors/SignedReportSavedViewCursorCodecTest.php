<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Cursors;

use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportSavedViewCursorCodec;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SignedReportSavedViewCursorCodecTest extends TestCase
{
    public function test_it_rejects_cursor_for_another_owner(): void
    {
        $codec = new SignedReportSavedViewCursorCodec('test-key', new DateTimeImmutable('2026-07-26T00:00:00+00:00'));
        $token = $codec->encode(1, 10, new DateTimeImmutable('2026-07-25T00:00:00+00:00'), '01J00000000000000000000000', 'projects');

        $this->expectExceptionObject(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::fromCode(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_CURSOR_INVALID));
        $codec->decode($token, 1, 11, 'projects');
    }

    public function test_it_rejects_non_canonical_saved_view_id_when_creating_cursor(): void
    {
        $codec = new SignedReportSavedViewCursorCodec('test-key', new DateTimeImmutable('2026-07-26T00:00:00+00:00'));

        $this->expectExceptionObject(\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException::fromCode(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_CURSOR_INVALID));
        $codec->encode(1, 10, new DateTimeImmutable('2026-07-25T00:00:00+00:00'), 'not-a-ulid', 'projects');
    }

    public function test_it_round_trips_unfiltered_cursor(): void
    {
        $codec = new SignedReportSavedViewCursorCodec('test-key', new DateTimeImmutable('2026-07-26T00:00:00+00:00'));
        $token = $codec->encode(1, 10, new DateTimeImmutable('2026-07-25T00:00:00+00:00'), '01J00000000000000000000000', null);

        self::assertSame('2026-07-25T00:00:00.000000+00:00|01J00000000000000000000000', $codec->decode($token, 1, 10, null));
    }
}
