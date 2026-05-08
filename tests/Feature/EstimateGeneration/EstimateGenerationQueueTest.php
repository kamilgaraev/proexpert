<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EstimateGenerationQueueTest extends TestCase
{
    public function test_generate_dispatches_estimate_generation_job_to_dedicated_queue(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeGenerationSession('analyzed');
        $request = Request::create('/generate', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationController::class)->generate($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('queued', $payload['data']['status']);
        $this->assertSame('queued', $payload['data']['processing_stage']);

        Queue::assertPushed(
            GenerateEstimateDraftJob::class,
            static fn (GenerateEstimateDraftJob $job): bool => $job->queue === GenerateEstimateDraftJob::QUEUE
        );

        $this->assertDatabaseHas('estimate_generation_sessions', [
            'id' => $session->id,
            'status' => 'queued',
            'processing_stage' => 'queued',
            'processing_progress' => 40,
        ]);
    }

    public function test_generation_job_marks_session_failed_when_generation_fails(): void
    {
        [, , $session] = $this->makeGenerationSession('queued');
        $job = new GenerateEstimateDraftJob($session->id);

        $job->failed(new RuntimeException(str_repeat('Ошибка генерации ', 50)));

        $session->refresh();

        $this->assertSame('failed', $session->status);
        $this->assertSame('failed', $session->processing_stage);
        $this->assertSame(0, $session->processing_progress);
        $this->assertNotNull($session->last_error);
        $this->assertLessThanOrEqual(500, mb_strlen($session->last_error));
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeGenerationSession(string $status): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => $status,
            'processing_stage' => $status,
            'processing_progress' => 35,
            'input_payload' => [
                'description' => 'Монолитные работы жилого дома',
            ],
            'analysis_payload' => [
                'detected_structure' => [],
            ],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }
}
