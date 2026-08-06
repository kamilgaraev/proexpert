<?php

declare(strict_types=1);

namespace Tests\Unit\CompletedWork\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsSource;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsSourceSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

final class AcceptedProductionOptionsServiceTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $connection;

    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousResolver = Model::getConnectionResolver();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->connection = $this->database->getConnection();

        foreach ([
            'CREATE TABLE work_types (id INTEGER PRIMARY KEY, organization_id INTEGER, name TEXT, code TEXT NULL)',
            'CREATE TABLE completed_works (id INTEGER PRIMARY KEY, organization_id INTEGER, project_id INTEGER, work_type_id INTEGER)',
            'CREATE TABLE contracts (id INTEGER PRIMARY KEY, organization_id INTEGER)',
            'CREATE TABLE contract_performance_acts (id INTEGER PRIMARY KEY, contract_id INTEGER, project_id INTEGER, act_document_number TEXT NULL)',
            'CREATE TABLE contractors (id INTEGER PRIMARY KEY, organization_id INTEGER, name TEXT)',
        ] as $statement) {
            $this->connection->statement($statement);
        }

        $this->connection->table('work_types')->insert([
            ['id' => 11, 'organization_id' => 1, 'name' => 'Бетонирование', 'code' => 'БТ'],
            ['id' => 12, 'organization_id' => 2, 'name' => 'Чужая работа', 'code' => null],
        ]);
        $this->connection->table('completed_works')->insert([
            ['id' => 101, 'organization_id' => 1, 'project_id' => 10, 'work_type_id' => 11],
            ['id' => 102, 'organization_id' => 2, 'project_id' => 20, 'work_type_id' => 12],
        ]);
        $this->connection->table('contracts')->insert([
            ['id' => 201, 'organization_id' => 1],
            ['id' => 202, 'organization_id' => 2],
        ]);
        $this->connection->table('contract_performance_acts')->insert([
            ['id' => 301, 'contract_id' => 201, 'project_id' => 10, 'act_document_number' => 'А-7'],
            ['id' => 302, 'contract_id' => 202, 'project_id' => 20, 'act_document_number' => 'Ч-1'],
        ]);
        $this->connection->table('contractors')->insert([
            ['id' => 401, 'organization_id' => 1, 'name' => 'Монолит'],
            ['id' => 402, 'organization_id' => 2, 'name' => 'Чужой подрядчик'],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }

        parent::tearDown();
    }

    public function test_options_resolve_only_canonical_source_references_inside_server_scope(): void
    {
        $options = $this->service(AcceptedProductionOptionsSourceSnapshot::available(
            workIds: [101],
            actIds: [301],
            contractorIds: [401],
            unitCodes: ['м3'],
            zones: ['Секция 1'],
            statuses: ['accepted', 'reversed'],
        ))->options(
            $this->scope(),
            10,
            new DateTimeImmutable('2026-08-06T14:15:16.123456+03:00'),
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-06'),
        );

        self::assertTrue($options['available']);
        self::assertNull($options['reason']);
        self::assertSame('2026-08-06T14:15:16.123456+03:00', $options['as_of']);
        self::assertSame('2026-08-01', $options['period_from']);
        self::assertSame('2026-08-06', $options['period_to']);
        self::assertSame([['id' => 101, 'name' => 'Работа №101 — Бетонирование']], $options['works']);
        self::assertSame([['id' => 301, 'name' => 'Акт №А-7']], $options['acts']);
        self::assertSame([['id' => 401, 'name' => 'Монолит']], $options['contractors']);
        self::assertSame([['id' => 'м3', 'name' => 'м3']], $options['units']);
        self::assertSame([['id' => 'Секция 1', 'name' => 'Секция 1']], $options['zones']);
        self::assertSame([
            ['id' => 'reversed', 'name' => 'Отменено'],
            ['id' => 'accepted', 'name' => 'Принято'],
        ], $options['statuses']);
    }

    public function test_missing_scoped_reference_makes_all_options_unavailable(): void
    {
        $options = $this->service(AcceptedProductionOptionsSourceSnapshot::available(
            workIds: [102],
            actIds: [302],
            contractorIds: [402],
            unitCodes: ['шт'],
            zones: [],
            statuses: ['accepted'],
        ))->options(
            $this->scope(),
            10,
            new DateTimeImmutable('2026-08-06T14:15:16+03:00'),
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-06'),
        );

        self::assertFalse($options['available']);
        self::assertSame('source_reference_missing', $options['reason']);
        self::assertSame([], $options['works']);
        self::assertSame([], $options['acts']);
        self::assertSame([], $options['contractors']);
        self::assertSame([], $options['units']);
    }

    public function test_incomplete_source_never_returns_partial_options(): void
    {
        $options = $this->service(
            AcceptedProductionOptionsSourceSnapshot::unavailable('source_incomplete'),
        )->options(
            $this->scope(),
            10,
            new DateTimeImmutable('2026-08-06T14:15:16+03:00'),
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-06'),
        );

        self::assertFalse($options['available']);
        self::assertSame('source_incomplete', $options['reason']);
        self::assertSame([], $options['works']);
        self::assertSame([], $options['statuses']);
    }

    public function test_trusted_route_project_narrows_authorized_multi_project_scope(): void
    {
        $options = $this->service(AcceptedProductionOptionsSourceSnapshot::available(
            workIds: [101],
            actIds: [301],
            contractorIds: [401],
            unitCodes: ['м3'],
            zones: [],
            statuses: ['accepted'],
        ))->options(
            $this->scope([10, 20]),
            10,
            new DateTimeImmutable('2026-08-06T14:15:16+03:00'),
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-06'),
        );

        self::assertTrue($options['available']);
        self::assertSame([['id' => 101, 'name' => 'Работа №101 — Бетонирование']], $options['works']);
    }

    public function test_route_project_outside_authorized_scope_is_rejected(): void
    {
        $this->expectException(ReportContractException::class);

        try {
            $this->service(AcceptedProductionOptionsSourceSnapshot::available(
                workIds: [],
                actIds: [],
                contractorIds: [],
                unitCodes: [],
                zones: [],
                statuses: [],
            ))->options(
                $this->scope([10]),
                20,
                new DateTimeImmutable('2026-08-06T14:15:16+03:00'),
                new DateTimeImmutable('2026-08-01'),
                new DateTimeImmutable('2026-08-06'),
            );
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);

            throw $exception;
        }
    }

    public function test_narrowing_keeps_other_project_resource_restrictions_fail_closed(): void
    {
        $resource = new ReportScopedResource('work', 102, 20);
        $options = $this->service(
            AcceptedProductionOptionsSourceSnapshot::available(
                workIds: [],
                actIds: [],
                contractorIds: [],
                unitCodes: [],
                zones: [],
                statuses: [],
            ),
            [$resource],
        )->options(
            $this->scope([10, 20], [$resource]),
            10,
            new DateTimeImmutable('2026-08-06T14:15:16+03:00'),
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-08-06'),
        );

        self::assertTrue($options['available']);
        self::assertSame([], $options['works']);
    }

    /** @param list<ReportScopedResource> $expectedResources */
    private function service(
        AcceptedProductionOptionsSourceSnapshot $snapshot,
        array $expectedResources = [],
    ): AcceptedProductionOptionsService {
        $source = new class($snapshot, $expectedResources) implements AcceptedProductionOptionsSource
        {
            /** @param list<ReportScopedResource> $expectedResources */
            public function __construct(
                private readonly AcceptedProductionOptionsSourceSnapshot $snapshot,
                private readonly array $expectedResources,
            ) {}

            public function snapshot(ReportScope $scope, ReportQuery $query): AcceptedProductionOptionsSourceSnapshot
            {
                if ($scope->projectIds !== [10]
                    || $query->scope->projectIds !== [10]
                    || $query->filters->values['project_id'] !== 10
                    || array_map(
                        static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(),
                        $scope->resources,
                    ) !== array_map(
                        static fn (ReportScopedResource $resource): array => $resource->canonicalIdentity(),
                        $this->expectedResources,
                    )
                ) {
                    throw new \LogicException('accepted_production_options_project_scope_invalid');
                }

                return $this->snapshot;
            }
        };

        return new AcceptedProductionOptionsService(
            $source,
            new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract),
            $this->connection,
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @param  list<ReportScopedResource>  $resources
     */
    private function scope(array $projectIds = [10], array $resources = []): ReportScope
    {
        return new ReportScope(1, [1], $projectIds, $resources, new DateTimeZone('Europe/Moscow'));
    }
}
