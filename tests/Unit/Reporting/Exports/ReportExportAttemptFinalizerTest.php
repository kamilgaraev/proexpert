<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Exports;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportAttemptLifecycleStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportAttemptFinalizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportExportAttemptFinalizerTest extends TestCase
{
    public function test_it_persists_only_safe_catalogued_failure_codes(): void
    {
        $store = new class implements ReportExportAttemptLifecycleStore
        {
            public array $codes = [];

            public function claimOrRenew(
                string $exportId,
                string $envelopeUuid,
                DateTimeImmutable $leaseExpiresAt,
                DateTimeImmutable $occurredAt,
            ): bool {
                return false;
            }

            public function failLeased(
                string $exportId,
                string $envelopeUuid,
                ReportErrorCode $errorCode,
                DateTimeImmutable $occurredAt,
            ): bool {
                $this->codes[] = $errorCode;

                return true;
            }
        };
        $finalizer = new ReportExportAttemptFinalizer($store);
        $uuid = '0195e44b-a9e7-7f12-a8af-51f2d284d3ef';
        $at = new DateTimeImmutable('2026-07-26T10:00:00Z');

        self::assertTrue($finalizer->finalize(
            '01J00000000000000000000000',
            $uuid,
            ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED),
            $at,
        ));
        self::assertTrue($finalizer->finalize(
            '01J00000000000000000000000',
            $uuid,
            new RuntimeException('secret'),
            $at,
        ));
        self::assertSame(
            [ReportErrorCode::REPORT_DEPENDENCY_FAILED, ReportErrorCode::REPORT_INTERNAL_ERROR],
            $store->codes,
        );
    }
}
