<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportDrillDownHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRowsHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportCoordinator;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportExecutionService;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\ReportExportRendererRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use App\BusinessModules\Core\Reporting\ReportingCatalogServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use App\BusinessModules\Core\Reporting\ReportingExecutionServiceProvider;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\Support\Reporting\FakeReportDrillDownProvider;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\FakeReportRowQuery;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportExportBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class PlanOneBPublishedBindingConsumptionTest extends TestCase
{
    private const RUN_ID = '01J00000000000000000000000';

    public function test_plan_one_b_consumers_use_plan_one_a_registry_and_binding_contracts(): void
    {
        $expected = [
            ReportRunCoordinator::class => [ReportDefinitionRegistry::class],
            GetReportRowsHandler::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingMap::class,
            ],
            GetReportDrillDownHandler::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingMap::class,
            ],
            ReportExportCoordinator::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingMap::class,
            ],
            ReportExportExecutionService::class => [
                ReportDefinitionRegistry::class,
                ReportDefinitionBindingMap::class,
            ],
        ];

        foreach ($expected as $class => $contracts) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            $types = [];
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    $types[] = $type->getName();
                }
            }
            foreach ($contracts as $contract) {
                self::assertContains($contract, $types, $class);
            }
            self::assertNotContains(CandidateReportDefinitionRegistry::class, $types, $class);
        }

        $jobBindings = (new ReflectionClass(MaterializeReportRunJob::class))
            ->getMethod('handle')
            ->getParameters()[4]
            ->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $jobBindings);
        self::assertSame(ReportDefinitionBindingMap::class, $jobBindings->getName());
    }

    public function test_production_provider_order_invokes_run_rows_drill_and_export_from_one_published_map(): void
    {
        $app = new Application(dirname(__DIR__, 3));
        (new ReportingContractsServiceProvider($app))->register();
        (new ReportingExecutionServiceProvider($app))->register();
        (new ReportingCatalogServiceProvider($app))->register();

        $now = new DateTimeImmutable('2026-07-29T07:00:00.000000Z');
        $clock = new FakeReportExecutionClock($now);
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor(1, 'active', ['reports.view', 'reports.run', 'reports.export']))
            ->build();
        [$published, $query, $run, $source] = $this->source($context, $now);
        $sort = new ReportWindowSort('name', ReportSortDirection::ASC);
        $page = new ReportPage(
            [['row_key' => 'row-1', 'name' => 'Строка']],
            [],
            ReportFreshnessStatus::FRESH,
            $source->result->quality,
            null,
            50,
            false,
            $sort,
        );
        $rowQuery = new FakeReportRowQuery($page, []);
        $drillResult = new ReportDrillDownResult(
            [['row_key' => 'row-1', 'name' => 'Детализация']],
            null,
            [],
        );
        $drillDown = new FakeReportDrillDownProvider($drillResult);
        $binding = new ReportDefinitionBinding(
            $published->code,
            $published->definitionHash,
            $published->definition->contractVersion,
            $this->createStub(ReportDataProvider::class),
            $rowQuery,
            $drillDown,
            null,
        );
        $registry = new class($published) implements ReportDefinitionRegistry
        {
            public int $publishedCalls = 0;

            public function __construct(private readonly PublishedReportDefinition $definition) {}

            public function published(string $code): PublishedReportDefinition
            {
                $this->publishedCalls++;

                if (! hash_equals($code, $this->definition->code)) {
                    throw new \LogicException('unexpected_published_code');
                }

                return $this->definition;
            }

            public function publishedCodes(): array
            {
                return [$this->definition->code];
            }

            public function manifestSha256(): Sha256Hash
            {
                return new Sha256Hash(str_repeat('f', 64));
            }
        };
        $candidate = new class implements CandidateReportDefinitionRegistry
        {
            public int $calls = 0;

            public function candidate(string $code): CandidateReportDefinition
            {
                $this->calls++;

                throw new \LogicException('candidate_registry_must_not_be_used');
            }

            public function candidateCodes(): array
            {
                $this->calls++;

                throw new \LogicException('candidate_registry_must_not_be_used');
            }
        };

        $app->instance(ReportDefinitionRegistry::class, $registry);
        $app->instance(CandidateReportDefinitionRegistry::class, $candidate);
        $app->instance(ReportExecutionClock::class, $clock);
        $app->instance(
            SignedReportCursorCodec::class,
            new SignedReportCursorCodec(['cursor-v1' => str_repeat('a', 64)], 'cursor-v1', $clock),
        );
        $assembler = $app->make(ReportDefinitionBindingAssembler::class);
        $assembler->register($binding);
        $bindings = $app->make(ReportDefinitionBindingMap::class);

        self::assertSame($bindings, $app->make(ReportDefinitionBindingMap::class));
        self::assertSame($binding, $bindings->get($published->code));

        $store = $this->createMock(ReportRunStore::class);
        $store->method('createOrReuse')->willReturn($run);
        $store->method('get')->willReturn($run);
        $store->method('queryForRun')->willReturn($query);
        $store->method('exportSource')->willReturn($source);
        $app->instance(ReportRunStore::class, $store);
        $app->instance(
            ReportSavedViewReferenceResolver::class,
            $this->createStub(ReportSavedViewReferenceResolver::class),
        );
        $actorLoader = $this->createStub(ReportActorLoader::class);
        $actorLoader->method('loadActive')->willReturn($context->actor);
        $app->instance(ReportActorLoader::class, $actorLoader);
        $app->instance(
            ReportSourceAccessResolver::class,
            $this->createStub(ReportSourceAccessResolver::class),
        );

        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorization = static fn ($target): CurrentReportAuthorization => new CurrentReportAuthorization(
            $context->actor,
            $context->authorization,
            $context->visibility,
            $target,
        );
        $authorizer->method('authorizeExact')->willReturnCallback(
            static fn (int $actorId, $scope, $target): CurrentReportAuthorization => $authorization($target),
        );
        $authorizer->method('authorizeExactMany')->willReturnCallback(
            static fn (int $actorId, $scope, array $targets): array => array_map($authorization, $targets),
        );
        $app->instance(CurrentReportScopeAuthorizer::class, $authorizer);

        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            self::RUN_ID,
            $published->definition,
            $context->scope,
            $source->snapshot,
            null,
            null,
        );
        $subjects = $this->createMock(
            \App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader::class,
        );
        $subjects->method('run')->willReturn($subject);
        $app->instance(
            \App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader::class,
            $subjects,
        );
        $expectedExport = (new ReportExportBuilder)
            ->runId(self::RUN_ID)
            ->format('csv')
            ->columns(['name'])
            ->queued();
        $exports = $this->createMock(ReportExportStore::class);
        $exports->method('createOrReuse')->willReturn($expectedExport);
        $app->instance(ReportExportStore::class, $exports);
        $app->instance(
            ReportExportRendererRegistry::class,
            new ReportExportRendererRegistry(
                new CsvReportExportRenderer,
                new XlsxReportExportRenderer,
                (new ReflectionClass(PdfReportExportRenderer::class))->newInstanceWithoutConstructor(),
            ),
        );

        $createdRun = $app->make(CreateReportRunAction::class)->handle(
            $context,
            new CreateReportRunData(
                $published->code,
                new ReportFilterSet([]),
                [],
                $now,
                'ru',
                null,
            ),
            new IdempotencyKey('run-key-0001'),
        );
        $rows = $app->make(GetReportRowsAction::class);
        $actualPage = $rows->handle($context, self::RUN_ID, new ReportRowsWindow(null, 50, $sort));
        $drill = $app->make(GetReportDrillDownAction::class);
        $token = $app->make(SignedReportCursorCodec::class)->encodeDrillDownCell(
            $context->scope->organizationId,
            $published->code,
            self::RUN_ID,
            $source->snapshot,
            $run->queryHash,
            'row-1',
            'name',
            $run->expiresAt,
        );
        $actualDrill = $drill->handle(
            $context,
            self::RUN_ID,
            new ReportDrillDownRequest($token, null, 25),
        );
        $actualExport = $app->make(CreateReportExportAction::class)->handle(
            $context,
            self::RUN_ID,
            new CreateReportExportData(
                'csv',
                ['name'],
                $sort,
                'ru',
                new DateTimeZone('Europe/Moscow'),
            ),
            new IdempotencyKey('export-key-0001'),
        );

        self::assertSame($run, $createdRun);
        self::assertSame($page, $actualPage);
        self::assertSame($drillResult, $actualDrill);
        self::assertSame($expectedExport, $actualExport);
        self::assertSame($bindings, $this->property($rows, 'bindings'));
        self::assertSame($bindings, $this->property($drill, 'bindings'));
        self::assertSame(
            $bindings,
            $this->property($this->property($app->make(CreateReportExportAction::class), 'coordinator'), 'bindings'),
        );
        self::assertGreaterThanOrEqual(3, $registry->publishedCalls);
        self::assertSame(0, $candidate->calls);
        self::assertCount(1, $rowQuery->pageCalls());
        self::assertCount(1, $drillDown->calls());
    }

    public function test_plan_one_b_binding_contract_publishes_the_exact_map_type(): void
    {
        $method = (new ReflectionClass(ReportDefinitionBindingAssembler::class))
            ->getMethod('assemble');
        $return = $method->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $return);
        self::assertSame(ReportDefinitionBindingMap::class, $return->getName());
        self::assertSame(
            ReportDefinitionRegistry::class,
            $method->getParameters()[0]->getType()?->getName(),
        );
    }

    private function source(
        ReportExecutionContext $context,
        DateTimeImmutable $now,
    ): array {
        $output = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            [],
            [],
            false,
            false,
            false,
        );
        $published = (new ReportDefinitionBuilder)
            ->columns([['id' => 'name']])
            ->formats(['csv'])
            ->outputClassification($output)
            ->published();
        $query = new ReportQuery(
            $published->definition,
            $context->scope,
            new ReportFilterSet([]),
            [],
            $now,
            'ru',
        );
        $sourceHash = new Sha256Hash(str_repeat('c', 64));
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
        $run = (new ReportRunBuilder)
            ->id(self::RUN_ID)
            ->reportCode($published->code)
            ->definitionHash($published->definitionHash)
            ->contractVersion($published->definition->contractVersion)
            ->formulaVersion($published->definition->formulaVersion)
            ->sourceSchemaVersion($published->definition->sourceSchemaVersion)
            ->rendererVersion($published->definition->rendererVersion)
            ->queryHash($query->queryHash)
            ->sourceHash($sourceHash)
            ->quality($quality)
            ->updatedAt($now)
            ->readyAt($now)
            ->expiresAt($now->modify('+1 day'))
            ->ready();
        $snapshot = $run->resultMetadata->snapshot;
        $provenance = new ReportProvenance(
            'system',
            [new ReportSourceRef('system', 'table', 'snapshot_1', 'v1', 'wm_1', 1, $sourceHash)],
            $sourceHash,
            null,
        );
        $metadata = new ReportResultMetadata($snapshot, 1, $now, null);
        $result = new ReportResult(
            $metadata,
            [],
            ReportFreshnessStatus::FRESH,
            $quality,
            $provenance,
            [['id' => 'name']],
            [],
        );
        $projection = (new ReflectionClass(ReportRunExportSource::class))
            ->getMethod('resultProjection')
            ->invoke(null, $result);
        $source = new ReportRunExportSource(
            $run,
            $query,
            $result,
            new Sha256Hash(hash('sha256', CanonicalJson::encode($projection))),
            $snapshot,
            ReportDataClassification::STANDARD,
            $output,
            $published->definition->contractVersion,
            $published->definition->formulaVersion,
            $published->definition->sourceSchemaVersion,
            $published->definition->rendererVersion,
        );

        return [$published, $query, $run, $source];
    }

    private function property(object $object, string $name): object
    {
        $value = (new ReflectionClass($object))->getProperty($name)->getValue($object);
        self::assertIsObject($value);

        return $value;
    }
}
