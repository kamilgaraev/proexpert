<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportHttpAuthorizationTargetResolver;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class LaravelReportHttpAuthorizationTargetResolverTest extends TestCase
{
    private const RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private const EXPORT_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    public function test_create_run_and_catalog_use_only_published_registry_snapshot(): void
    {
        $alpha = $this->definition('alpha_report', 'a');
        $zeta = $this->definition('zeta_report', 'f');
        $resolver = new LaravelReportHttpAuthorizationTargetResolver(
            new StubDefinitionRegistry([$zeta, $alpha]),
            new StubSubjectReader,
        );

        $create = $resolver->createRun('alpha_report');
        $catalog = $resolver->catalog();

        self::assertSame($alpha, $create->definition);
        self::assertSame(ReportOperation::RUN, $create->operation);
        self::assertNull($create->snapshot);
        self::assertSame(
            ['alpha_report', 'zeta_report'],
            array_map(
                static fn (CurrentReportAuthorizationTarget $target): string => $target->definition->code,
                $catalog,
            ),
        );
        self::assertSame(
            [ReportOperation::VIEW, ReportOperation::VIEW],
            array_map(
                static fn (CurrentReportAuthorizationTarget $target): ReportOperation => $target->operation,
                $catalog,
            ),
        );
    }

    #[DataProvider('runOperationProvider')]
    public function test_run_resolution_uses_persisted_subject_revision_and_fixed_snapshot_rule(
        ReportOperation $operation,
        bool $expectsSnapshot,
    ): void {
        $subject = $this->runSubject();
        $reader = new StubSubjectReader($subject);
        $resolver = new LaravelReportHttpAuthorizationTargetResolver(
            new StubDefinitionRegistry([$this->definition('current_report', 'f')]),
            $reader,
        );

        $target = $resolver->run(self::RUN_ID, $operation);

        self::assertSame([self::RUN_ID], $reader->runIds);
        self::assertSame($subject->definition, $target->definition);
        self::assertSame($operation, $target->operation);
        self::assertSame($expectsSnapshot ? $subject->snapshot : null, $target->snapshot);
    }

    #[DataProvider('exportOperationProvider')]
    public function test_export_resolution_uses_only_persisted_export_subject(ReportOperation $operation): void
    {
        $subject = $this->exportSubject();
        $reader = new StubSubjectReader(export: $subject);
        $resolver = new LaravelReportHttpAuthorizationTargetResolver(
            new StubDefinitionRegistry([$this->definition('current_report', 'f')]),
            $reader,
        );

        $target = $resolver->export(self::EXPORT_ID, $operation);

        self::assertSame([self::EXPORT_ID], $reader->exportIds);
        self::assertSame($subject->definition, $target->definition);
        self::assertSame($operation, $target->operation);
        self::assertSame($subject->snapshot, $target->snapshot);
        self::assertSame($subject->exportFormat, $target->exportFormat);
    }

    public function test_create_export_uses_ready_parent_subject_and_exact_snapshot(): void
    {
        $subject = $this->runSubject();
        $reader = new StubSubjectReader($subject);
        $resolver = new LaravelReportHttpAuthorizationTargetResolver(
            new StubDefinitionRegistry([$subject->definition]),
            $reader,
        );

        $target = $resolver->createExport(self::RUN_ID, 'csv');

        self::assertSame(ReportOperation::EXPORT, $target->operation);
        self::assertSame($subject->snapshot, $target->snapshot);
        self::assertSame($subject->definition, $target->definition);
        self::assertSame('csv', $target->exportFormat);
    }

    public function test_reader_cannot_replay_an_export_subject_as_a_run(): void
    {
        $resolver = new LaravelReportHttpAuthorizationTargetResolver(
            new StubDefinitionRegistry([$this->definition()]),
            new StubSubjectReader($this->exportSubject()),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_target_source_invalid');

        $resolver->run(self::RUN_ID, ReportOperation::VIEW);
    }

    #[DataProvider('invalidSourceOperationProvider')]
    public function test_invalid_source_operation_pair_is_rejected(
        string $source,
        ReportOperation $operation,
    ): void {
        $resolver = new LaravelReportHttpAuthorizationTargetResolver(
            new StubDefinitionRegistry([$this->definition()]),
            new StubSubjectReader($this->runSubject(), $this->exportSubject()),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_authorization_target_source_invalid');

        $source === 'run'
            ? $resolver->run(self::RUN_ID, $operation)
            : $resolver->export(self::EXPORT_ID, $operation);
    }

    public static function runOperationProvider(): array
    {
        return [
            'show' => [ReportOperation::VIEW, true],
            'retry or cancel' => [ReportOperation::RUN, false],
            'rows' => [ReportOperation::VIEW, true],
            'drill down' => [ReportOperation::DRILL_DOWN, true],
        ];
    }

    public static function exportOperationProvider(): array
    {
        return [
            'show' => [ReportOperation::VIEW],
            'retry or cancel' => [ReportOperation::EXPORT],
            'download' => [ReportOperation::DOWNLOAD],
        ];
    }

    public static function invalidSourceOperationProvider(): array
    {
        return [
            'run cannot download' => ['run', ReportOperation::DOWNLOAD],
            'run cannot export via generic resolver' => ['run', ReportOperation::EXPORT],
            'export cannot run' => ['export', ReportOperation::RUN],
            'export cannot drill down' => ['export', ReportOperation::DRILL_DOWN],
        ];
    }

    private function runSubject(): ReportAuthorizationSubject
    {
        $definition = $this->definition();
        $scope = $this->scope();

        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            self::RUN_ID,
            $definition,
            $scope,
            $this->snapshot($scope, $definition),
            null,
            null,
        );
    }

    private function exportSubject(): ReportAuthorizationSubject
    {
        $definition = $this->definition();
        $scope = $this->scope();

        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            self::EXPORT_ID,
            $definition,
            $scope,
            $this->snapshot($scope, $definition),
            self::RUN_ID,
            new Sha256Hash(str_repeat('e', 64)),
            null,
            $definition->formats[0],
        );
    }

    private function definition(string $code = 'report', string $hash = 'a'): ReportDefinition
    {
        return (new ReportDefinitionBuilder)
            ->code($code)
            ->definitionHash(new Sha256Hash(str_repeat($hash, 64)))
            ->payload();
    }

    private function scope(): ReportScope
    {
        return new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
    }

    private function snapshot(ReportScope $scope, ReportDefinition $definition): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            'snapshot',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('b', 64)),
            new DateTimeImmutable('2026-07-29T00:00:00.000000Z'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}

final class StubDefinitionRegistry implements ReportDefinitionRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $definitions = [];

    /** @param list<ReportDefinition> $definitions */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->code] = $definition;
        }
    }

    public function published(string $code): PublishedReportDefinition
    {
        return new PublishedReportDefinition(
            $this->definitions[$code] ?? throw new InvalidArgumentException('report_not_found'),
        );
    }

    public function publishedCodes(): array
    {
        return array_keys($this->definitions);
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('0', 64));
    }
}

final class StubSubjectReader implements ReportAuthorizationSubjectReader
{
    /** @var list<string> */
    public array $runIds = [];

    /** @var list<string> */
    public array $exportIds = [];

    public function __construct(
        private readonly ?ReportAuthorizationSubject $run = null,
        private readonly ?ReportAuthorizationSubject $export = null,
    ) {}

    public function run(string $runId): ReportAuthorizationSubject
    {
        $this->runIds[] = $runId;

        return $this->run ?? throw new InvalidArgumentException('report_not_found');
    }

    public function export(string $exportId): ReportAuthorizationSubject
    {
        $this->exportIds[] = $exportId;

        return $this->export ?? throw new InvalidArgumentException('report_not_found');
    }
}
