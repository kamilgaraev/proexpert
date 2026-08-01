<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportCompletedArtifactRecoveryStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportCompletedArtifactRecoveryStoreContractTest extends TestCase
{
    public function test_recovery_store_exposes_only_the_expired_upload_claim(): void
    {
        $reflection = new ReflectionClass(ReportCompletedArtifactRecoveryStore::class);

        self::assertSame(['claimExpiredUpload'], array_map(
            static fn ($method): string => $method->getName(),
            $reflection->getMethods(),
        ));
        $method = $reflection->getMethod('claimExpiredUpload');
        self::assertSame(
            [
                ReportExecutionContext::class,
                'string',
                'string',
                DateTimeImmutable::class,
                DateTimeImmutable::class,
            ],
            array_map(
                static fn ($parameter): string => $parameter->getType()?->getName() ?? '',
                $method->getParameters(),
            ),
        );
        self::assertSame(ReportExport::class, $method->getReturnType()?->getName());
    }
}
