<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPipelineCheckpoint;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class EloquentPipelineCheckpointStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_current_owner_can_complete_reclaimed_checkpoint(): void
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
            'state_version' => 0,
        ]);
        $store = new EloquentPipelineCheckpointStore(DB::connection());
        $context = new PipelineContext($session->id, $organization->id, $project->id, 0, 'sha256:input');
        $started = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
        $old = $store->claim($context, ProcessingStage::UnderstandObject, $started, $started->modify('+1 second'));
        $fresh = $store->claim(
            $context,
            ProcessingStage::UnderstandObject,
            $started->modify('+2 seconds'),
            $started->modify('+1 minute'),
        );
        $result = new PipelineStageResult(ProcessingStage::UnderstandObject, 'sha256:output', []);

        self::assertSame(CheckpointClaimStatus::Acquired, $fresh->status);
        self::assertFalse($store->complete($old, $result, $started->modify('+3 seconds')));
        self::assertTrue($store->complete($fresh, $result, $started->modify('+3 seconds')));
        self::assertSame(1, EstimateGenerationPipelineCheckpoint::query()->count());
    }
}
