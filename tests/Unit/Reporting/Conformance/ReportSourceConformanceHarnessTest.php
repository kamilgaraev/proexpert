<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Conformance;

use App\BusinessModules\Core\Reporting\Application\Conformance\ReportSourceConformanceHarness;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportConformanceFixture;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Conformance\FilesystemReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\Reporting\FakeReportDataProvider;
use Tests\Support\Reporting\FakeReportDrillDownProvider;
use Tests\Support\Reporting\FakeReportRowQuery;
use Tests\Support\Reporting\ReportConformanceFixtureBuilder;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportSourceConformanceHarnessTest extends TestCase
{
    public function test_published_wrapper_cannot_enter_candidate_harness(): void
    {
        $method = new ReflectionMethod(ReportSourceConformanceHarness::class, 'verify');

        self::assertSame(
            CandidateReportDefinition::class,
            $method->getParameters()[0]->getType()?->getName(),
        );
    }

    public function test_candidate_fixture_produces_complete_passed_evidence(): void
    {
        $scenario = $this->scenario();

        $evidence = $this->verify($scenario);

        self::assertTrue($evidence->passed());
        self::assertSame('passed', $evidence->status);
        self::assertSame(15, $evidence->assertionCount);
        self::assertSame('fixture', $evidence->source->snapshotKind);
        self::assertSame(2, $evidence->source->rowCount);
        self::assertSame('quality_report', $evidence->code);
        self::assertSame(str_repeat('a', 64), $evidence->definitionHash->value);
        self::assertSame('1', $evidence->contractVersion);
        self::assertSame('v1', $evidence->sourceSchemaVersion);
        self::assertSame(str_repeat('f', 64), $evidence->fixtureHash->value);
        self::assertTrue($evidence->source->passed);
        self::assertTrue($evidence->formula->passed);
        self::assertSame('fixture-1', $evidence->source->snapshotId);
        self::assertCount(13, $evidence->source->assertionCodes);
        self::assertCount(2, $evidence->formula->assertionCodes);
        self::assertTrue($this->verify($this->scenario(completeProgress: false))->passed());
    }

    public function test_unavailable_result_returns_failed_evidence(): void
    {
        $scenario = $this->scenario(freshness: ReportFreshnessStatus::UNAVAILABLE);

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.availability.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
    }

    public function test_definition_binding_hash_drift_returns_failed_evidence(): void
    {
        $scenario = $this->scenario();
        $scenario['binding'] = new ReportDefinitionBinding(
            $scenario['binding']->code,
            new Sha256Hash(str_repeat('b', 64)),
            $scenario['binding']->contractVersion,
            $scenario['binding']->dataProvider,
            $scenario['binding']->rowQuery,
            $scenario['binding']->drillDownProvider,
            null,
        );

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.binding_identity.failed', $evidence->source->assertionCodes);

        $sourceDrift = $this->verify($this->scenario(sourceHashDrift: true));
        self::assertFalse($sourceDrift->passed());
        self::assertContains('source.source_hash.failed', $sourceDrift->source->assertionCodes);
    }

    public function test_query_definition_hash_drift_returns_failed_evidence(): void
    {
        $scenario = $this->scenario();
        $other = (new ReportDefinitionBuilder)
            ->code('quality_report')
            ->definitionHash(new Sha256Hash(str_repeat('b', 64)))
            ->candidate()
            ->payload();
        $scenario['query'] = new ReportQuery(
            $other,
            $scenario['context']->scope,
            new ReportFilterSet(['period' => '2026-07']),
            [],
            new DateTimeImmutable('2026-07-26T00:00:00+00:00'),
            'ru',
        );

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.query_identity.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
        self::assertSame('quality_report', $evidence->code);
    }

    public function test_owner_scope_drift_returns_failed_evidence(): void
    {
        $scenario = $this->scenario();
        $timezone = new DateTimeZone('UTC');
        $scenario['query'] = new ReportQuery(
            $scenario['candidate']->payload(),
            new ReportScope(2, [2], [], [], $timezone),
            new ReportFilterSet(['period' => '2026-07']),
            [],
            new DateTimeImmutable('2026-07-26T00:00:00+00:00'),
            'ru',
        );

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.scope.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
    }

    public function test_duplicate_cursor_row_keys_return_failed_evidence(): void
    {
        $scenario = $this->scenario(cursorRows: [
            ['row_key' => 'row-1', 'name' => 'A', 'amount' => '10.00'],
            ['row_key' => 'row-1', 'name' => 'B', 'amount' => '20.00'],
        ]);

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.unique_row_keys.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
        self::assertContains('source.row_count.passed', $evidence->source->assertionCodes);
    }

    public function test_page_cursor_semantic_drift_returns_failed_evidence(): void
    {
        $scenario = $this->scenario(cursorRows: [
            ['row_key' => 'row-2', 'name' => 'B', 'amount' => '20.00'],
            ['row_key' => 'row-1', 'name' => 'A', 'amount' => '10.00'],
        ]);

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.page_cursor_semantics.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
        self::assertContains('source.unique_row_keys.passed', $evidence->source->assertionCodes);
    }

    public function test_nonfinite_cursor_value_returns_failed_evidence(): void
    {
        $scenario = $this->scenario(cursorRows: [
            ['row_key' => 'row-1', 'name' => 'A', 'amount' => INF],
            ['row_key' => 'row-2', 'name' => 'B', 'amount' => '20.00'],
        ]);

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.canonical_values.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
        self::assertSame(str_repeat('0', 64), $evidence->source->rowsHash->value);
    }

    public function test_sensitive_row_leakage_returns_failed_evidence(): void
    {
        $rows = [
            ['row_key' => 'row-1', 'name' => 'A', 'amount' => '10.00', 'pii' => 'secret'],
            ['row_key' => 'row-2', 'name' => 'B', 'amount' => '20.00'],
        ];
        $scenario = $this->scenario(pageRows: $rows, cursorRows: $rows);

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.sensitive_redaction.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
        self::assertContains('source.canonical_values.passed', $evidence->source->assertionCodes);
    }

    public function test_unsigned_or_unavailable_resource_link_returns_failed_evidence(): void
    {
        $scenario = $this->scenario(resourceAvailability: 'missing');

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('source.resource_links.failed', $evidence->source->assertionCodes);
        self::assertSame('failed', $evidence->status);
        self::assertFalse($evidence->source->passed);
        self::assertContains('source.sensitive_redaction.passed', $evidence->source->assertionCodes);
    }

    public function test_totals_hash_drift_returns_failed_formula_evidence(): void
    {
        $scenario = $this->scenario();
        $scenario['fixture'] = (new ReportConformanceFixtureBuilder)
            ->expectedTotalsHash(new Sha256Hash(str_repeat('e', 64)))
            ->build();

        $evidence = $this->verify($scenario);

        self::assertFalse($evidence->passed());
        self::assertContains('formula.totals.failed', $evidence->formula->assertionCodes);
        self::assertFalse($evidence->formula->passed);
        self::assertTrue($evidence->source->passed);
        self::assertSame('failed', $evidence->status);
    }

    public function test_fixture_enforces_exact_page_and_cursor_limits(): void
    {
        foreach ([
            static fn (): ReportConformanceFixture => (new ReportConformanceFixtureBuilder)
                ->pageLimit(0)
                ->build(),
            static fn (): ReportConformanceFixture => (new ReportConformanceFixtureBuilder)
                ->pageLimit(101)
                ->build(),
            static fn (): ReportConformanceFixture => (new ReportConformanceFixtureBuilder)
                ->cursorChunkSize(0)
                ->build(),
            static fn (): ReportConformanceFixture => (new ReportConformanceFixtureBuilder)
                ->cursorChunkSize(5001)
                ->build(),
        ] as $invalidFixture) {
            try {
                $invalidFixture();
                self::fail('Invalid fixture limit must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_filesystem_repository_uses_canonical_path_and_round_trips_validated_evidence(): void
    {
        $scenario = $this->scenario();
        $evidence = $this->verify($scenario);
        $root = $this->temporaryRepository();
        $repository = new FilesystemReportConformanceEvidenceRepository(
            $root,
            new Draft202012SchemaValidator(new CompliantValidator),
        );

        try {
            $repository->put($evidence);
            $path = $root.'/build/reports/conformance/quality_report/'
                .$evidence->definitionHash->value.'/'.$evidence->fixtureHash->value.'.json';

            self::assertFileExists($path);
            self::assertSame(
                $evidence->digest()->value,
                $repository->get(
                    $evidence->code,
                    $evidence->definitionHash,
                    $evidence->fixtureHash,
                )->digest()->value,
            );
            $stored = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($evidence->digest()->value, $stored['digest']);
            self::assertSame('quality_report', $stored['code']);
            self::assertSame('passed', $stored['status']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function verify(array $scenario): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence
    {
        return (new ReportSourceConformanceHarness)->verify(
            $scenario['candidate'],
            $scenario['binding'],
            $scenario['context'],
            $scenario['query'],
            $scenario['fixture'],
            str_repeat('1', 40),
            new DateTimeImmutable('2026-07-26T12:00:00+00:00'),
        );
    }

    private function scenario(
        ReportFreshnessStatus $freshness = ReportFreshnessStatus::FRESH,
        ?array $pageRows = null,
        ?array $cursorRows = null,
        string $resourceAvailability = 'available',
        bool $sourceHashDrift = false,
        bool $completeProgress = true,
    ): array {
        $candidate = (new ReportDefinitionBuilder)
            ->code('quality_report')
            ->sourceSchemaVersion('v1')
            ->candidate();
        $context = (new ReportExecutionContextBuilder)->build();
        $query = new ReportQuery(
            $candidate->payload(),
            $context->scope,
            new ReportFilterSet(['period' => '2026-07']),
            [],
            new DateTimeImmutable('2026-07-26T00:00:00+00:00'),
            'ru',
        );
        $rows = $pageRows ?? [
            ['row_key' => 'row-1', 'name' => 'A', 'amount' => '10.00'],
            ['row_key' => 'row-2', 'name' => 'B', 'amount' => '20.00'],
        ];
        $cursorRows ??= [
            ['amount' => '10.00', 'name' => 'A', 'row_key' => 'row-1'],
            ['name' => 'B', 'row_key' => 'row-2', 'amount' => '20.00'],
        ];
        $totals = ['amount' => '30.00'];
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            new ReportCoverage('2', '2', '1.0'),
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
        $generatedAt = new DateTimeImmutable('2026-07-26T10:00:00+00:00');
        $sourceRefHash = new Sha256Hash(str_repeat('c', 64));
        $placeholder = new Sha256Hash(str_repeat('d', 64));
        [$snapshot, $result] = $this->snapshotAndResult(
            $query,
            $context->scope,
            $generatedAt,
            $placeholder,
            $sourceRefHash,
            $totals,
            $quality,
            $freshness,
        );
        $sourceHash = (new CanonicalReportSourceHashBuilder)->build($query, $snapshot, $result);
        if ($sourceHashDrift) {
            $sourceHash = new Sha256Hash(str_repeat('b', 64));
        }
        [$snapshot, $result] = $this->snapshotAndResult(
            $query,
            $context->scope,
            $generatedAt,
            $sourceHash,
            $sourceRefHash,
            $totals,
            $quality,
            $freshness,
        );
        $page = new ReportPage(
            $rows,
            $totals,
            $freshness,
            $quality,
            null,
            2,
            false,
            (new ReportConformanceFixtureBuilder)->build()->sort,
        );
        $drill = new ReportDrillDownResult(
            [['row_key' => 'detail-1', 'name' => 'Detail']],
            null,
            [new ReportResourceLink(
                'project',
                'project_1',
                'admin.projects.show',
                ['project_id' => 1],
                $resourceAvailability,
            )],
        );
        $dataProvider = $completeProgress
            ? new FakeReportDataProvider($snapshot, $result)
            : new NonProgressingConformanceDataProvider($snapshot, $result);
        $binding = new ReportDefinitionBinding(
            $candidate->code,
            $candidate->definitionHash,
            $candidate->payload()->contractVersion,
            $dataProvider,
            new FakeReportRowQuery($page, $cursorRows),
            new FakeReportDrillDownProvider($drill),
            null,
        );

        return [
            'candidate' => $candidate,
            'binding' => $binding,
            'context' => $context,
            'query' => $query,
            'fixture' => (new ReportConformanceFixtureBuilder)->build(),
        ];
    }

    private function snapshotAndResult(
        ReportQuery $query,
        ReportScope $scope,
        DateTimeImmutable $generatedAt,
        Sha256Hash $sourceHash,
        Sha256Hash $sourceRefHash,
        array $totals,
        ReportQuality $quality,
        ReportFreshnessStatus $freshness,
    ): array {
        $snapshot = new ReportSnapshotRef(
            'fixture',
            'fixture-1',
            $scope,
            $query->definition->definitionHash,
            $query->definition->formulaVersion,
            $sourceHash,
            $generatedAt,
            null,
            ['source' => '2026_07'],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $provenance = new ReportProvenance(
            'accounting',
            [new ReportSourceRef(
                'accounting',
                'fixture',
                'fixture_1',
                $query->definition->sourceSchemaVersion,
                'period_2026_07',
                2,
                $sourceRefHash,
            )],
            $sourceHash,
            null,
        );
        $result = new ReportResult(
            new ReportResultMetadata($snapshot, 2, $generatedAt, null),
            $totals,
            $freshness,
            $quality,
            $provenance,
            [
                ['id' => 'name'],
                ['id' => 'amount'],
            ],
            [],
        );

        return [$snapshot, $result];
    }

    private function temporaryRepository(): string
    {
        $path = sys_get_temp_dir().'/most-conformance-'.bin2hex(random_bytes(6));
        mkdir($path.'/docs/reports/contracts', 0777, true);
        copy(
            dirname(__DIR__, 4).'/docs/reports/contracts/report-conformance-evidence.schema.json',
            $path.'/docs/reports/contracts/report-conformance-evidence.schema.json',
        );

        return str_replace('\\', '/', $path);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $path.'/'.$item;
            if (is_dir($target) && ! is_link($target)) {
                $this->removeDirectory($target);
            } else {
                unlink($target);
            }
        }
        rmdir($path);
    }
}

final readonly class NonProgressingConformanceDataProvider implements ReportDataProvider
{
    public function __construct(
        private ReportSnapshotRef $snapshot,
        private ReportResult $reportResult,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        return $this->snapshot;
    }

    public function result(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): ReportResult {
        return $this->reportResult;
    }
}
