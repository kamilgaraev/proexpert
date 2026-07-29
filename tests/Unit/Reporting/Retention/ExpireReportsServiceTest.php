<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Retention;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Retention\ExpireReportsService;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ExpireReportsServiceTest extends TestCase
{
    public function test_exposes_only_the_bounded_closed_summary_contract(): void
    {
        $method = new ReflectionMethod(ExpireReportsService::class, 'expire');

        self::assertTrue($method->isPublic());
        self::assertSame(['limit', 'occurredAt'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
        self::assertSame('array', (string) $method->getReturnType());
    }

    public function test_rejects_an_unbounded_batch_before_database_access(): void
    {
        $service = new ExpireReportsService(
            new RecordingRetentionAudit,
            new ReportRunHydrator,
            new ReportExportHydrator,
        );

        foreach ([0, 501] as $limit) {
            try {
                $service->expire($limit, new DateTimeImmutable('2026-07-30T10:00:00.123456Z'));
                self::fail('Unbounded retention batch was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_retention_batch_size_invalid', $exception->getMessage());
            }
        }
    }

    public function test_retention_does_not_extend_the_locked_run_store_surface(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ReportRunStore::class))->getMethods(),
        );
        sort($methods, SORT_STRING);

        self::assertSame([
            'cancel',
            'claimMaterialization',
            'createOrReuse',
            'exportSource',
            'fail',
            'get',
            'persistProgress',
            'queryForRun',
            'retrySource',
            'sealReady',
        ], $methods);
    }
}

final class RecordingRetentionAudit implements ReportTransitionAudit
{
    public array $events = [];

    public function append(
        string $eventId,
        string $eventType,
        ReportExecutionContext $context,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->events[] = compact('eventId', 'eventType', 'context', 'subject', 'occurredAt');
    }
}
