<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use InvalidArgumentException;

final readonly class LaravelReportHttpAuthorizationTargetResolver implements ReportHttpAuthorizationTargetResolver
{
    public function __construct(
        private ReportDefinitionRegistry $definitions,
        private ReportAuthorizationSubjectReader $subjects,
    ) {}

    public function createRun(string $reportCode): CurrentReportAuthorizationTarget
    {
        return new CurrentReportAuthorizationTarget(
            $this->definitions->published($reportCode)->payload(),
            ReportOperation::RUN,
            null,
        );
    }

    public function run(string $runId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        if (! in_array($operation, [ReportOperation::VIEW, ReportOperation::RUN, ReportOperation::DRILL_DOWN], true)) {
            throw new InvalidArgumentException('report_authorization_target_source_invalid');
        }

        $subject = $this->subjects->run($runId);
        $this->assertSubject($subject->aggregateKind, $subject->aggregateId, ReportDispatchAggregate::RUN, $runId);

        return new CurrentReportAuthorizationTarget(
            $subject->definition,
            $operation,
            $operation === ReportOperation::RUN ? null : $subject->snapshot,
        );
    }

    public function createExport(string $runId, ?string $format): CurrentReportAuthorizationTarget
    {
        $subject = $this->subjects->run($runId);
        $this->assertSubject($subject->aggregateKind, $subject->aggregateId, ReportDispatchAggregate::RUN, $runId);
        if ($subject->snapshot === null) {
            throw new InvalidArgumentException('report_authorization_target_source_invalid');
        }

        return new CurrentReportAuthorizationTarget(
            $subject->definition,
            ReportOperation::EXPORT,
            $subject->snapshot,
            $format,
        );
    }

    public function export(string $exportId, ReportOperation $operation): CurrentReportAuthorizationTarget
    {
        if (! in_array($operation, [ReportOperation::VIEW, ReportOperation::EXPORT, ReportOperation::DOWNLOAD], true)) {
            throw new InvalidArgumentException('report_authorization_target_source_invalid');
        }

        $subject = $this->subjects->export($exportId);
        $this->assertSubject(
            $subject->aggregateKind,
            $subject->aggregateId,
            ReportDispatchAggregate::EXPORT,
            $exportId,
        );

        return new CurrentReportAuthorizationTarget(
            $subject->definition,
            $operation,
            $subject->snapshot,
            $subject->exportFormat,
        );
    }

    public function catalog(): array
    {
        $codes = $this->definitions->publishedCodes();
        sort($codes, SORT_STRING);

        return array_map(
            fn (string $code): CurrentReportAuthorizationTarget => new CurrentReportAuthorizationTarget(
                $this->definitions->published($code)->payload(),
                ReportOperation::VIEW,
                null,
            ),
            $codes,
        );
    }

    private function assertSubject(
        ReportDispatchAggregate $actualKind,
        string $actualId,
        ReportDispatchAggregate $expectedKind,
        string $expectedId,
    ): void {
        if ($actualKind !== $expectedKind || $actualId !== $expectedId) {
            throw new InvalidArgumentException('report_authorization_target_source_invalid');
        }
    }
}
