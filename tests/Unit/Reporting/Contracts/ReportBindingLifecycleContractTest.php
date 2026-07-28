<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportDataProvider;
use Tests\Support\Reporting\FakeReportDrillDownProvider;
use Tests\Support\Reporting\FakeReportReadinessProbe;
use Tests\Support\Reporting\FakeReportRowQuery;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportBindingLifecycleContractTest extends TestCase
{
    #[Test]
    public function candidate_and_published_wrappers_are_nominally_separated(): void
    {
        $candidate = (new ReportDefinitionBuilder())->candidate();
        $registry = new class($candidate) implements ReportDefinitionRegistry {
            public function __construct(private readonly CandidateReportDefinition $candidate) {}
            public function published(string $code): PublishedReportDefinition { return $this->candidate; }
            public function publishedCodes(): array { return []; }
            public function manifestSha256(): \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash { return new \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash(str_repeat('a', 64)); }
        };

        $this->expectException(\TypeError::class);
        $registry->published('report');
    }

    #[Test]
    public function assembler_rejects_candidate_registry_before_implementation(): void
    {
        $assembler = new class implements ReportDefinitionBindingAssembler {
            public function register(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding $binding): void {}
            public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap { return new ReportDefinitionBindingMap([]); }
        };
        $registry = new class implements CandidateReportDefinitionRegistry {
            public function candidate(string $code): CandidateReportDefinition { return (new ReportDefinitionBuilder())->candidate(); }
            public function candidateCodes(): array { return []; }
        };

        $this->expectException(\TypeError::class);
        $assembler->assemble($registry);
    }

    #[Test]
    public function candidate_validation_result_rejects_duplicate_codes_and_sorts_items(): void
    {
        $first = new ReportCandidateValidationItem('beta', $this->hash('b'), true, []);
        $second = new ReportCandidateValidationItem('alpha', $this->hash('a'), false, ['FAIL_B', 'FAIL_A']);
        $result = new ReportCandidateValidationResult([$first, $second]);

        self::assertFalse($result->passed());
        self::assertSame('alpha', $result->item('alpha')->code);
        self::assertSame(['FAIL_A', 'FAIL_B'], $result->item('alpha')->failureCodes);
        self::assertSame(['alpha', 'beta'], array_map(static fn (ReportCandidateValidationItem $item): string => $item->code, $result->items));

        $this->expectException(\InvalidArgumentException::class);
        new ReportCandidateValidationResult([$first, $first]);
    }

    #[Test]
    public function binding_map_requires_matching_keys_and_missing_lookup_is_domain_not_found(): void
    {
        $binding = $this->binding('alpha');
        $map = new ReportDefinitionBindingMap(['alpha' => $binding]);

        self::assertSame($binding, $map->get('alpha'));
        self::assertSame(['alpha'], array_keys($map->all()));

        try {
            $map->get('missing');
            self::fail('Missing binding was returned.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }

        $this->expectException(\InvalidArgumentException::class);
        new ReportDefinitionBindingMap(['other' => $binding]);
    }

    #[Test]
    public function empty_binding_map_is_valid_and_reports_missing_codes_as_not_found(): void
    {
        $map = new ReportDefinitionBindingMap([]);

        self::assertSame([], $map->all());
        try {
            $map->get('missing');
            self::fail('Missing binding was returned.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }
    }

    #[Test]
    public function fakes_record_exact_calls_and_keep_rows_repeatable(): void
    {
        $definition = (new ReportDefinitionBuilder())->payload();
        $context = (new ReportExecutionContextBuilder())->build();
        $snapshot = $this->snapshot();
        $result = $this->reportResult($snapshot);
        $page = $this->page();
        $query = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery($definition, $context->scope, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet([]), [], new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 'ru');
        $progress = new ReportProgress(0);
        $dataProvider = new FakeReportDataProvider($snapshot, $result);
        $rows = new FakeReportRowQuery($page, (static function (): \Generator { yield ['row_key' => 'one']; })());
        $drillDown = new FakeReportDrillDownProvider(new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult([], null, []));
        $probe = new FakeReportReadinessProbe(true);
        $sort = new ReportWindowSort('name', ReportSortDirection::ASC);

        self::assertSame($snapshot, $dataProvider->materialize($context, $query, $progress));
        self::assertSame(100, $progress->percent());
        self::assertSame($result, $dataProvider->result($context, $snapshot));
        self::assertSame($page, $rows->page($context, $snapshot, $sort, null, 10));
        self::assertSame([['row_key' => 'one']], iterator_to_array($rows->cursor($context, $snapshot, $sort, 10)));
        self::assertSame([['row_key' => 'one']], iterator_to_array($rows->cursor($context, $snapshot, $sort, 10)));
        self::assertSame(2, count($rows->cursorCalls()));
        self::assertTrue($probe->supports($definition));
        self::assertSame([$definition], $probe->definitions());
        self::assertCount(1, $dataProvider->materializeCalls());
        self::assertCount(1, $dataProvider->resultCalls());
        self::assertSame([$context, $query, $progress], $dataProvider->materializeCalls()[0]);
        self::assertSame([$context, $snapshot], $dataProvider->resultCalls()[0]);
        self::assertSame([$context, $snapshot, $sort, null, 10], $rows->pageCalls()[0]);
        self::assertSame([$context, $snapshot, $sort, 10], $rows->cursorCalls()[0]);
        self::assertSame([$context, $snapshot, $sort, 10], $rows->cursorCalls()[1]);
        $this->expectException(LogicException::class);
        $dataProvider->result($context, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef('report', 'other', $context->scope, $this->hash('a'), '1', $this->hash('b'), new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, [], \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL, null));
    }

    #[Test]
    public function fake_row_query_preserves_cursor_type_and_builders_make_valid_states(): void
    {
        $rows = new FakeReportRowQuery($this->page(), []);
        $context = (new ReportExecutionContextBuilder())->build();
        $snapshot = $this->snapshot();
        $sort = new ReportWindowSort('name', ReportSortDirection::ASC);

        $this->expectException(\TypeError::class);
        $rows->page($context, $snapshot, $sort, 'bad', 10);
    }

    #[Test]
    public function builders_produce_queued_and_ready_run_and_export_states(): void
    {
        self::assertSame('queued', (new ReportRunBuilder())->queued()->status->value);
        self::assertSame('ready', (new ReportRunBuilder())->ready()->status->value);
        self::assertSame('queued', (new ReportExportBuilder())->queued()->status->value);
        self::assertSame('ready', (new ReportExportBuilder())->ready()->status->value);
    }

    #[Test]
    public function binding_keeps_its_complete_contract_identity(): void
    {
        $binding = $this->binding('alpha');

        self::assertSame('alpha', $binding->code);
        self::assertSame(str_repeat('a', 64), $binding->definitionHash->value);
        self::assertSame('1', $binding->contractVersion);
    }

    #[Test]
    public function passed_candidate_item_has_no_failure_codes(): void
    {
        $item = new ReportCandidateValidationItem('alpha', $this->hash('a'), true, []);

        self::assertSame('alpha', $item->code);
        self::assertTrue($item->passed);
        self::assertSame([], $item->failureCodes);
    }

    #[Test]
    public function missing_candidate_item_is_repeatably_reported_as_not_found(): void
    {
        $result = new ReportCandidateValidationResult([]);

        foreach (['first', 'second'] as $code) {
            try {
                $result->item($code);
                self::fail('Missing item was returned.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
            }
        }
    }

    #[Test]
    public function all_passing_candidate_items_produce_a_passing_result(): void
    {
        $result = new ReportCandidateValidationResult([
            new ReportCandidateValidationItem('beta', $this->hash('b'), true, []),
            new ReportCandidateValidationItem('alpha', $this->hash('a'), true, []),
        ]);

        self::assertTrue($result->passed());
        self::assertSame('alpha', $result->items[0]->code);
        self::assertSame('beta', $result->items[1]->code);
    }

    #[Test]
    public function fake_drill_down_provider_records_the_full_argument_tuple(): void
    {
        $context = (new ReportExecutionContextBuilder())->build();
        $snapshot = $this->snapshot();
        $request = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest('token', null, 10);
        $result = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult([], null, []);
        $provider = new FakeReportDrillDownProvider($result);

        self::assertSame($result, $provider->drillDown($context, $snapshot, $request));
        self::assertCount(1, $provider->calls());
        self::assertSame([$context, $snapshot, $request], $provider->calls()[0]);
    }

    #[Test]
    public function definition_builder_creates_nominal_wrappers_with_fixed_defaults(): void
    {
        $builder = new ReportDefinitionBuilder();
        $candidate = $builder->candidate();
        $published = $builder->published();

        self::assertSame('report', $candidate->code);
        self::assertSame('report', $published->code);
        self::assertSame('candidate', $candidate->payload()->publicationReadiness->value);
        self::assertSame('published', $published->payload()->publicationReadiness->value);
        self::assertSame(str_repeat('a', 64), $candidate->definitionHash->value);
        self::assertSame(str_repeat('a', 64), $published->definitionHash->value);
    }

    #[Test]
    public function every_run_builder_setter_is_observable_in_a_valid_state(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01T00:00:30+00:00');
        $definitionHash = new Sha256Hash(str_repeat('d', 64));
        $queryHash = new Sha256Hash(str_repeat('e', 64));
        $sourceHash = new Sha256Hash(str_repeat('f', 64));
        $defaultSourceHash = new Sha256Hash(str_repeat('c', 64));
        $scope = (new ReportExecutionContextBuilder())->build()->scope;
        $metadata = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata(
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef('report', 'snapshot2', $scope, new Sha256Hash(str_repeat('a', 64)), '1', $defaultSourceHash, new \DateTimeImmutable('2026-01-01T00:01:00+00:00'), null, [], \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL, null),
            0,
            new \DateTimeImmutable('2026-01-01T00:01:00+00:00'),
            null,
        );
        $quality = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus::PARTIAL, null, [], 1, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus::MISMATCH, ['metric'], []);
        $provenance = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance('external', [new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef('external', 'report', 'snapshot2', 'v2', 'watermark2', 1, $defaultSourceHash)], $defaultSourceHash, null);
        $cases = [
            ['id', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->id('01J00000000000000000000002'), static fn ($run): string => $run->id, '01J00000000000000000000002', 'queued'],
            ['reportCode', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->reportCode('other'), static fn ($run): string => $run->reportCode, 'other', 'queued'],
            ['status', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->status(ReportRunStatus::MATERIALIZING), static fn ($run): ReportRunStatus => $run->status, ReportRunStatus::MATERIALIZING, 'queued'],
            ['definitionHash', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->definitionHash($definitionHash), static fn ($run): Sha256Hash => $run->definitionHash, $definitionHash, 'ready'],
            ['contractVersion', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->contractVersion('2'), static fn ($run): string => $run->contractVersion, '2', 'queued'],
            ['formulaVersion', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->formulaVersion('2'), static fn ($run): string => $run->formulaVersion, '2', 'ready'],
            ['sourceSchemaVersion', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->sourceSchemaVersion('2'), static fn ($run): string => $run->sourceSchemaVersion, '2', 'queued'],
            ['rendererVersion', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->rendererVersion('2'), static fn ($run): string => $run->rendererVersion, '2', 'queued'],
            ['queryHash', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->queryHash($queryHash), static fn ($run): Sha256Hash => $run->queryHash, $queryHash, 'queued'],
            ['sourceHash', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->sourceHash($sourceHash), static fn ($run): ?Sha256Hash => $run->sourceHash, $sourceHash, 'ready'],
            ['progress', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->progress(50), static fn ($run): int => $run->progress, 50, 'queued'],
            ['rowCount', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->rowCount(7), static fn ($run): ?int => $run->rowCount, 7, 'ready'],
            ['resultMetadata', static fn (ReportRunBuilder $builder) => $builder->resultMetadata($metadata), static fn ($run) => $run->resultMetadata, $metadata, 'ready', true],
            ['totals', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->totals(['sum' => 1]), static fn ($run): array => $run->totals, ['sum' => 1], 'ready'],
            ['freshness', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->freshness(ReportFreshnessStatus::STALE), static fn ($run) => $run->freshness, ReportFreshnessStatus::STALE, 'ready'],
            ['quality', static fn (ReportRunBuilder $builder) => $builder->quality($quality), static fn ($run) => $run->quality, $quality, 'ready', true],
            ['provenance', static fn (ReportRunBuilder $builder) => $builder->provenance($provenance), static fn ($run) => $run->provenance, $provenance, 'ready', true],
            ['createdAt', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->createdAt(new \DateTimeImmutable('2025-12-31T23:00:00+00:00')), static fn ($run) => $run->createdAt, new \DateTimeImmutable('2025-12-31T23:00:00+00:00'), 'queued'],
            ['updatedAt', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->updatedAt(new \DateTimeImmutable('2026-01-01T00:02:00+00:00')), static fn ($run) => $run->updatedAt, new \DateTimeImmutable('2026-01-01T00:02:00+00:00'), 'ready'],
            ['readyAt', static fn (ReportRunBuilder $builder) => $builder->readyAt($timestamp), static fn ($run) => $run->readyAt, $timestamp, 'ready'],
            ['expiresAt', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->expiresAt(new \DateTimeImmutable('2026-01-03T00:00:00+00:00')), static fn ($run) => $run->expiresAt, new \DateTimeImmutable('2026-01-03T00:00:00+00:00'), 'queued'],
            ['cancelRequestedAt', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->cancelRequestedAt(new \DateTimeImmutable('2026-01-01T00:00:30+00:00')), static fn ($run) => $run->cancelRequestedAt, new \DateTimeImmutable('2026-01-01T00:00:30+00:00'), 'queued'],
            ['httpDisposition', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->httpDisposition('reused'), static fn ($run): string => $run->httpDisposition, 'reused', 'queued'],
            ['pollAfterMs', static fn (ReportRunBuilder $builder): ReportRunBuilder => $builder->pollAfterMs(2500), static fn ($run): ?int => $run->pollAfterMs, 2500, 'queued'],
        ];

        foreach ($cases as $case) {
            [$name, $apply, $read, $expected, $state, $identity] = $case + [null, null, null, null, null, false];
            $builder = new ReportRunBuilder();
            $apply($builder);
            $run = $state === 'ready' ? $builder->ready() : $builder->queued();

            if ($identity) {
                self::assertSame($expected, $read($run), $name);
            } else {
                self::assertEquals($expected, $read($run), $name);
            }
        }
    }

    #[Test]
    public function every_export_builder_setter_is_observable_in_a_valid_state(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01T00:00:30+00:00');
        $exportHash = new Sha256Hash(str_repeat('a', 64));
        $checksum = new Sha256Hash(str_repeat('f', 64));
        $cases = [
            ['id', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->id('01J00000000000000000000002'), static fn ($export): string => $export->id, '01J00000000000000000000002', 'queued'],
            ['runId', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->runId('01J00000000000000000000003'), static fn ($export): string => $export->runId, '01J00000000000000000000003', 'queued'],
            ['status', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->status(ReportExportStatus::RUNNING), static fn ($export) => $export->status, ReportExportStatus::RUNNING, 'queued'],
            ['exportHash', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->exportHash($exportHash), static fn ($export) => $export->exportHash, $exportHash, 'queued'],
            ['format', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->format('xlsx'), static fn ($export): string => $export->format, 'xlsx', 'queued'],
            ['columns', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->columns(['total']), static fn ($export): array => $export->columns, ['total'], 'queued'],
            ['sort', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->sort(new ReportWindowSort('total', ReportSortDirection::DESC)), static fn ($export) => $export->sort, new ReportWindowSort('total', ReportSortDirection::DESC), 'queued'],
            ['locale', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->locale('en'), static fn ($export): string => $export->locale, 'en', 'queued'],
            ['timezone', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->timezone(new \DateTimeZone('Europe/Moscow')), static fn ($export) => $export->timezone, new \DateTimeZone('Europe/Moscow'), 'queued'],
            ['artifactPath', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->artifactPath('org-1/reports/custom.csv'), static fn ($export) => $export->artifactPath, 'org-1/reports/custom.csv', 'ready'],
            ['versionId', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->versionId('version2'), static fn ($export) => $export->versionId, 'version2', 'ready'],
            ['etag', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->etag('etag2'), static fn ($export) => $export->etag, 'etag2', 'ready'],
            ['checksum', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->checksum($checksum), static fn ($export) => $export->checksum, $checksum, 'ready'],
            ['sizeBytes', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->sizeBytes(2), static fn ($export) => $export->sizeBytes, 2, 'ready'],
            ['rowCount', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->rowCount(7), static fn ($export) => $export->rowCount, 7, 'ready'],
            ['createdAt', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->createdAt(new \DateTimeImmutable('2025-12-31T23:00:00+00:00')), static fn ($export) => $export->createdAt, new \DateTimeImmutable('2025-12-31T23:00:00+00:00'), 'queued'],
            ['updatedAt', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->updatedAt(new \DateTimeImmutable('2026-01-01T00:02:00+00:00')), static fn ($export) => $export->updatedAt, new \DateTimeImmutable('2026-01-01T00:02:00+00:00'), 'ready'],
            ['readyAt', static fn (ReportExportBuilder $builder) => $builder->readyAt($timestamp), static fn ($export) => $export->readyAt, $timestamp, 'ready'],
            ['expiresAt', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->expiresAt(new \DateTimeImmutable('2026-01-03T00:00:00+00:00')), static fn ($export) => $export->expiresAt, new \DateTimeImmutable('2026-01-03T00:00:00+00:00'), 'queued'],
            ['cancelRequestedAt', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->cancelRequestedAt(new \DateTimeImmutable('2026-01-01T00:00:30+00:00')), static fn ($export) => $export->cancelRequestedAt, new \DateTimeImmutable('2026-01-01T00:00:30+00:00'), 'queued'],
            ['httpDisposition', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->httpDisposition('reused'), static fn ($export): string => $export->httpDisposition, 'reused', 'queued'],
            ['pollAfterMs', static fn (ReportExportBuilder $builder): ReportExportBuilder => $builder->pollAfterMs(2500), static fn ($export) => $export->pollAfterMs, 2500, 'queued'],
        ];

        foreach ($cases as [$name, $apply, $read, $expected, $state]) {
            $builder = new ReportExportBuilder();
            $apply($builder);
            $export = $state === 'ready' ? $builder->ready() : $builder->queued();

            self::assertEquals($expected, $read($export), $name);
        }
    }

    #[Test]
    public function builders_reject_states_incompatible_with_the_selected_output(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportRunBuilder())->sourceHash($this->hash('f'))->queued();
    }

    private function binding(string $code): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding
    {
        $snapshot = $this->snapshot();
        return new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding($code, $this->hash('a'), '1', new FakeReportDataProvider($snapshot, $this->reportResult($snapshot)), new FakeReportRowQuery($this->page(), []), new FakeReportDrillDownProvider(new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult([], null, [])), null);
    }

    private function hash(string $character): \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash
    {
        return new \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash(str_repeat($character, 64));
    }

    private function snapshot(): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef
    {
        $context = (new ReportExecutionContextBuilder())->build();
        return new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef('report', 'snapshot', $context->scope, $this->hash('a'), '1', $this->hash('b'), new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, [], \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OPERATIONAL, null);
    }

    private function reportResult(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef $snapshot): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult
    {
        $quality = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality(\App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus::COMPLETE, null, [], 0, \App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus::MATCHED, [], []);
        $provenance = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance('system', [new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef('system', 'report', 'snapshot', 'v1', 'watermark', 0, $snapshot->sourceHash)], $snapshot->sourceHash, null);
        return new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult(new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata($snapshot, 0, $snapshot->generatedAt, $snapshot->staleAt), [], \App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus::FRESH, $quality, $provenance, [], []);
    }

    private function page(): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage
    {
        $ready = (new ReportRunBuilder())->ready();
        return new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage([], [], \App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus::FRESH, $ready->quality, null, 10, false, new ReportWindowSort('name', ReportSortDirection::ASC));
    }
}
