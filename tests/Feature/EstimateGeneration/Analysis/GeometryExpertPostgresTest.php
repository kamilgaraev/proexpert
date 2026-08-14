<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\DeterministicGeometryCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\GeometryExpertModel;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\RunGeometryExpert;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry\VisionGeometryExpertModel;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class GeometryExpertPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function geometry_role_replays_exactly_and_switches_current_projection_without_losing_history(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('most_backend_testing', DB::getDatabaseName());
        self::assertInstanceOf(VisionGeometryExpertModel::class, app(GeometryExpertModel::class));
        self::assertInstanceOf(RunGeometryExpert::class, app(RunGeometryExpert::class));
        $this->ensureSchema();
        DB::beginTransaction();
        try {
            [$repository, $input, $scope, $evidenceId] = $this->fixture();
            $firstModel = new PostgresGeometryModel($this->sheets('10', '8', 'v1', $evidenceId));
            $firstService = new RunGeometryExpert(
                new PostgresGeometryRoleRunRepository,
                $repository,
                $firstModel,
                new DeterministicGeometryCalculator,
                'openai/gpt-5.6-luna',
            );

            $first = $firstService->run($input);
            $replay = $firstService->run($input);

            self::assertSame($first->toArray(), $replay->toArray());
            self::assertSame(1, $firstModel->calls);
            self::assertSame('80', $repository->currentDerivedQuantities(...$scope)[0]->value);
            self::assertCount(1, $repository->derivedQuantityHistory(...[...$scope, 'floor:1:area']));

            $this->saveOperands($repository, $scope, $evidenceId, 'v2', '12', '8', 2, 'v1');
            $secondService = new RunGeometryExpert(
                new PostgresGeometryRoleRunRepository,
                $repository,
                new PostgresGeometryModel($this->sheets('12', '8', 'v2', $evidenceId, 2)),
                new DeterministicGeometryCalculator,
                'openai/gpt-5.6-luna',
            );
            $secondService->run($input);

            $current = $repository->currentDerivedQuantities(...$scope);
            self::assertCount(1, $current);
            self::assertSame('96', $current[0]->value);
            self::assertCount(2, $repository->derivedQuantityHistory(...[...$scope, 'floor:1:area']));
            self::assertSame(1, DB::table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $scope[3])
                ->where('logical_key', 'floor:1:area')
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('estimate_generation_sessions')) {
            $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_03_24_100000_create_estimate_generation_sessions_table.php';
            $migration->up();
        }
        if (! Schema::hasTable('estimate_generation_project_model_derived_quantities')) {
            if (Schema::hasTable('estimate_generation_project_model_entities')) {
                $partial = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000200_create_estimate_generation_project_model_tables.php';
                $partial->down();
            }
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS eg_building_models_projection_scope_uq ON estimate_generation_building_models (id, organization_id, project_id, session_id, content_version)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS eg_building_model_evidence_projection_scope_uq ON estimate_generation_building_model_evidence (building_model_id, evidence_id, organization_id, project_id, session_id)');
            foreach ([
                '2026_08_01_000200_create_estimate_generation_project_model_tables.php',
                '2026_08_10_000600_consolidate_estimate_project_model_v2.php',
                '2026_08_10_000610_secure_estimate_project_model_v2_schema.php',
                '2026_08_12_000100_add_derived_quantity_current_projection.php',
            ] as $file) {
                $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/'.$file;
                $migration->up();
            }
        }
    }

    /** @return array{EloquentProjectModelRepository,GeometryExpertInput,array{int,int,int,string},int} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
        ]);
        $scope = [
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            'sha256:'.str_repeat('7', 64),
        ];
        $repository = new EloquentProjectModelRepository(app('db'));
        $storedEvidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(
            $scope[0],
            $scope[1],
            $scope[2],
            EvidenceType::Extracted,
            EvidenceSourceType::DocumentUnit,
            'document:1',
            'sha256:'.str_repeat('6', 64),
            ['document_id' => 1, 'page' => 4, 'bbox' => [10, 20, 40, 60], 'element_key' => 'element:42'],
            ['field_key' => 'wall_length', 'field_value' => 5, 'unit' => 'm'],
            1,
            'pdf_geometry',
            'extractor:v1',
        ));
        $model = (new BuildingModelRepository(
            new EloquentBuildingModelStore(DB::connection()),
            new EloquentEvidenceRepository(DB::connection()),
            $repository,
        ))->store(
            new BuildingModelOperationContext($scope[0], $scope[1], $scope[2], $scope[3]),
            new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
                new FloorData('source-floor', 0, 3, [], [], [], [], [$storedEvidence->id], 1, 'confirmed'),
            ], [], 'building-model:v1'),
        );
        $scope[3] = $model->contentVersion;
        $repository->saveSourceModel([
            new Entity('floor:1', $scope[0], $scope[1], $scope[2], $scope[3], 'quantity', 'floor:1', ['value' => 1, 'unit' => 'm2']),
        ], [], []);
        $this->saveOperands($repository, $scope, $storedEvidence->id, 'v1', '10', '8');

        return [
            $repository,
            new GeometryExpertInput($scope[0], $scope[1], $scope[2], $scope[3], [[
                'sheet_id' => 'page:4',
                'sheet_role' => 'plan',
                'page_number' => 4,
            ]]),
            $scope,
            $storedEvidence->id,
        ];
    }

    /** @param array{int,int,int,string} $scope */
    private function saveOperands(
        EloquentProjectModelRepository $repository,
        array $scope,
        int $evidenceDatabaseId,
        string $suffix,
        string $length,
        string $width,
        int $version = 1,
        ?string $supersedesSuffix = null,
    ): void {
        $evidence = [
            new Evidence('evidence:'.$evidenceDatabaseId, $scope[0], $scope[1], $scope[2], $scope[3], 'document:1', 'cad', 4, ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], 'element:42'),
        ];
        $repository->saveSourceModel([], [
            new Fact(
                'fact:length:'.$suffix,
                $scope[0], $scope[1], $scope[2], $scope[3],
                'floor:1',
                'length',
                $length,
                'm',
                1.0,
                'document',
                'confirmed',
                [$evidence[0]->id],
                $version,
                $supersedesSuffix === null ? null : 'fact:length:'.$supersedesSuffix,
            ),
            new Fact(
                'fact:width:'.$suffix,
                $scope[0], $scope[1], $scope[2], $scope[3],
                'floor:1',
                'width',
                $width,
                'm',
                1.0,
                'document',
                'confirmed',
                [$evidence[0]->id],
                $version,
                $supersedesSuffix === null ? null : 'fact:width:'.$supersedesSuffix,
            ),
        ], $evidence);
    }

    /** @return list<array<string,mixed>> */
    private function sheets(string $length, string $width, string $suffix, int $evidenceDatabaseId, int $version = 1): array
    {
        return [[
            'sheet_id' => 'page:4',
            'sheet_role' => 'plan',
            'page_number' => 4,
            'interpretations' => [[
                'quantity_id' => 'floor:1:area',
                'entity_id' => 'floor:1',
                'formula_id' => 'floor_area',
                'output_unit' => 'm2',
                'rounding_scale' => 6,
                'operands' => [
                    [
                        'name' => 'length', 'fact_id' => 'fact:length:'.$suffix,
                        'projection_version' => $version, 'value' => $length, 'unit' => 'm',
                        'evidence_id' => 'evidence:'.$evidenceDatabaseId, 'physical_locator' => 'dimension:length',
                    ],
                    [
                        'name' => 'width', 'fact_id' => 'fact:width:'.$suffix,
                        'projection_version' => $version, 'value' => $width, 'unit' => 'm',
                        'evidence_id' => 'evidence:'.$evidenceDatabaseId, 'physical_locator' => 'dimension:width',
                    ],
                ],
            ]],
        ]];
    }
}

final class PostgresGeometryModel implements GeometryExpertModel
{
    public int $calls = 0;

    public function __construct(private readonly array $sheets) {}

    public function interpret(GeometryExpertInput $input, callable $onPhysicalAttemptReserved): array
    {
        $this->calls++;
        $onPhysicalAttemptReserved('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        return $this->sheets;
    }
}

final class PostgresGeometryRoleRunRepository implements AiRoleRunRepository
{
    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }
}
