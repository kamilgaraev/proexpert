<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Jobs;

use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportExportExecutionLeasesCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GenerateReportExportJobTest extends TestCase
{
    public function test_job_payload_and_retry_runtime_are_closed(): void
    {
        $job = new GenerateReportExportJob('01J00000000000000000000000');
        $reflection = new ReflectionClass($job);

        self::assertSame(['exportId'], array_values(array_filter(
            array_keys(get_object_vars($job)),
            static fn (string $property): bool => ! in_array($property, [
                'tries',
                'timeout',
                'failOnTimeout',
                'job',
                'connection',
                'queue',
                'delay',
                'afterCommit',
                'middleware',
                'chained',
                'chainConnection',
                'chainQueue',
                'chainCatchCallbacks',
            ], true),
        )));
        self::assertSame(5, $job->tries);
        self::assertSame(900, $job->timeout);
        self::assertTrue($job->failOnTimeout);
        self::assertSame([30, 120, 300, 900], $job->backoff());
        self::assertFalse($reflection->hasMethod('failed'));
    }

    public function test_malformed_export_id_is_rejected_before_dispatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_export_id_invalid');

        new GenerateReportExportJob('invalid');
    }

    public function test_reconciliation_command_uses_zero_argument_handle(): void
    {
        $reflection = new ReflectionClass(
            ReconcileReportExportExecutionLeasesCommand::class,
        );

        self::assertCount(
            0,
            $reflection->getMethod('handle')->getParameters(),
        );
        self::assertStringContainsString(
            '{--limit=100}',
            (string) $reflection->getDefaultProperties()['signature'],
        );
    }
}
