<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateRevisionQueueService;
use App\Http\Controllers\Api\V1\Admin\EstimateVersionController;
use App\Http\Requests\Admin\Estimate\StartEstimateRevisionRequest;
use App\Jobs\CreateEstimateRevision;
use App\Models\Estimate;
use App\Models\EstimateRevisionOperation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EstimateRevisionQueueTest extends TestCase
{
    private bool $allowed = true;

    public function test_http_request_accepts_job_without_changing_estimate_and_repeat_is_idempotent(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::fake();
        $response = $this->request($estimate, $actor);
        $this->assertSame(202, $response->getStatusCode());
        $payload = $response->getData(true)['data'];
        $this->assertSame('queued', $payload['status']);
        $this->assertSame('approved', $estimate->fresh()->status);
        $this->assertSame($payload['id'], $this->request($estimate, $actor)->getData(true)['data']['id']);
        Queue::assertPushedOn('estimate-revisions', CreateEstimateRevision::class);
        Queue::assertPushed(CreateEstimateRevision::class, 1);
        $this->assertSame(409, $this->request($estimate, $actor, 'another-request-key')->getStatusCode());
    }

    public function test_worker_completes_once_and_completed_request_can_be_replayed(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::fake();
        $id = $this->request($estimate, $actor)->getData(true)['data']['id'];
        $service = app(EstimateRevisionQueueService::class);
        $service->process($id);
        $service->process($id);
        $this->assertSame('draft', $estimate->fresh()->status);
        $this->assertSame('completed', EstimateRevisionOperation::findOrFail($id)->status);
        $this->assertDatabaseCount('estimate_versions', 2);
        $this->assertSame(200, $this->request($estimate, $actor)->getStatusCode());
    }

    public function test_queue_failure_returns_safe_json_and_records_failure(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::shouldReceive('connection')->with('redis_estimate_revisions')
            ->andThrow(new \RuntimeException('private queue credential'));
        $response = $this->request($estimate, $actor);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertDatabaseHas('estimate_revision_operations', ['status' => 'failed', 'error_code' => 'dispatch_failed']);
        $this->assertSame('approved', $estimate->fresh()->status);
    }

    public function test_worker_rechecks_permissions_and_preserves_estimate_on_failure(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::fake();
        $id = $this->request($estimate, $actor)->getData(true)['data']['id'];
        $this->allowed = false;
        try {
            app(EstimateRevisionQueueService::class)->process($id);
            $this->fail('Expected authorization failure');
        } catch (AuthorizationException) {
            $this->assertSame('approved', $estimate->fresh()->status);
            $this->assertSame('failed', EstimateRevisionOperation::findOrFail($id)->status);
            $this->assertDatabaseCount('estimate_versions', 1);
        }
    }

    public function test_status_does_not_expose_another_organizations_operation(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::fake();
        $this->request($estimate, $actor);
        $this->expectException(ModelNotFoundException::class);
        app(EstimateRevisionQueueService::class)->latest($estimate->id, $estimate->organization_id + 999, $actor);
    }

    public function test_recovery_redispatches_lost_job_and_bounds_attempts(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::fake();
        $id = $this->request($estimate, $actor)->getData(true)['data']['id'];
        $this->travel(13)->minutes();
        app(EstimateRevisionQueueService::class)->recover();
        Queue::assertPushed(CreateEstimateRevision::class, 2);
        EstimateRevisionOperation::whereKey($id)->update(['dispatch_attempts' => 3]);
        $this->travel(13)->minutes();
        app(EstimateRevisionQueueService::class)->recover();
        $this->assertDatabaseHas('estimate_revision_operations', ['id' => $id, 'status' => 'failed', 'error_code' => 'worker_unavailable']);
    }

    public function test_timeout_callback_marks_task_failed_without_modifying_estimate(): void
    {
        [$estimate, $actor] = $this->fixture();
        Queue::fake();
        $id = $this->request($estimate, $actor)->getData(true)['data']['id'];
        (new CreateEstimateRevision($id))->failed(new \RuntimeException('timeout'));
        $this->assertSame('failed', EstimateRevisionOperation::findOrFail($id)->status);
        $this->assertSame('approved', $estimate->fresh()->status);
    }

    private function request(Estimate $estimate, User $actor, string $key = 'revision-request-0001'): \Illuminate\Http\JsonResponse
    {
        $this->actingAs($actor);
        $request = StartEstimateRevisionRequest::create('/api/v1/admin/estimates/'.$estimate->id.'/versions/revisions', 'POST', ['reason' => 'Изменение цены']);
        $request->headers->set('Idempotency-Key', $key);
        $request->setContainer($this->app);
        $request->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $actor);
        $request->attributes->set('current_organization_id', $estimate->organization_id);
        $this->app->instance('request', $request);
        $request->validateResolved();

        return app(EstimateVersionController::class)->startRevision($request, $estimate->id);
    }

    private function fixture(): array
    {
        Gate::before(fn (): bool => $this->allowed);
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'QUEUE-TEST', 'name' => 'Test estimate', 'type' => 'local',
            'status' => 'approved', 'estimate_date' => '2026-09-12', 'calculation_method' => 'resource',
            'approved_by_user_id' => $actor->id, 'approved_at' => now(),
            'total_amount' => 0, 'total_amount_with_vat' => 0,
        ]);
        app(EstimateVersioningService::class)->createSnapshot($estimate, $actor->id, snapshotType: 'approval');

        return [$estimate->fresh(), $actor];
    }
}
