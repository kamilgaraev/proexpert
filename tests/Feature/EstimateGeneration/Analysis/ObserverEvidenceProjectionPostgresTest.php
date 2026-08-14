<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class ObserverEvidenceProjectionPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function production_shaped_completed_observer_batch_is_atomic_and_keeps_only_canonical_project_model_candidates(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('1', getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'));

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 20,
                'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('5', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
            $locator = [
                'page' => 1,
                'unit_type' => 'pdf_page',
                'unit_index' => 1,
                'source_version' => $sourceVersion,
                'explicit' => true,
            ];
            $claims = [
                new ObservationClaim(
                    'literal:1', 'observer_literal', 'building.roof', 'roof_geometry',
                    ['type' => 'enum', 'data' => 'gable'], null, 'literal:roof', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
                new ObservationClaim(
                    'literal:2', 'observer_literal', 'room.1', 'room_area',
                    ['type' => 'number', 'data' => 24.5], 'm2', 'literal:room-area', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
            ];
            $writer = new ProjectModelEvidenceWriter(
                new EloquentProjectModelRepository(app('db')),
                new EloquentEvidenceRepository(DB::connection()),
            );

            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeIndependentObservations($claims, 173, 1);

            self::assertSame(1, DB::table('estimate_generation_project_model_entities')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(1, DB::table('estimate_generation_project_model_assertions')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(1, DB::table('estimate_generation_evidence')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->count());
        } finally {
            DB::rollBack();
        }
    }
}
