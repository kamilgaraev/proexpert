<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\Http\Controllers\Api\V1\Admin\EstimateController;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EstimateStructureSnapshotControllerTest extends TestCase
{
    public function test_show_streams_estimate_metadata_with_tree_from_s3_snapshot(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Gate::before(static fn (): bool => true);

        $estimate = $this->createEstimate([
            'structure_cache_path' => 'org-7/estimates/42/show_structure_snapshot.json',
        ]);

        Storage::disk('s3')->put($estimate->structure_cache_path, '{"sections":[{"id":1,"name":"S3"}]}');
        Storage::disk('local')->put($estimate->structure_cache_path, '{"sections":[{"id":999,"name":"Local"}]}');

        $response = $this->app->make(EstimateController::class)
            ->show($this->requestFor($estimate), $estimate->project_id, $estimate->id);

        ob_start();
        $response->sendContent();
        $payload = json_decode((string) ob_get_clean(), true);

        $this->assertTrue($payload['success']);
        $this->assertSame($estimate->id, $payload['data']['id']);
        $this->assertSame([['id' => 1, 'name' => 'S3']], $payload['tree']['sections']);
    }

    public function test_structure_streams_snapshot_from_s3_not_local_storage(): void
    {
        Storage::fake('s3');
        Storage::fake('local');
        Gate::before(static fn (): bool => true);

        $estimate = $this->createEstimate([
            'structure_cache_path' => 'org-7/estimates/42/structure_snapshot.json',
        ]);

        Storage::disk('s3')->put($estimate->structure_cache_path, '{"sections":[{"id":1,"name":"S3"}]}');
        Storage::disk('local')->put($estimate->structure_cache_path, '{"sections":[{"id":999,"name":"Local"}]}');

        $response = $this->app->make(EstimateController::class)
            ->structure($this->requestFor($estimate), $estimate->project_id, $estimate->id);

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertSame(
            '{"success":true,"message":null,"data":{"sections":[{"id":1,"name":"S3"}]}}',
            $content
        );
    }

    public function test_structure_uses_fallback_when_snapshot_is_missing(): void
    {
        Storage::fake('s3');
        Gate::before(static fn (): bool => true);

        $estimate = $this->createEstimate([
            'structure_cache_path' => 'org-7/estimates/42/missing_snapshot.json',
        ]);

        $response = $this->app->make(EstimateController::class)
            ->structure($this->requestFor($estimate), $estimate->project_id, $estimate->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['data']);
    }

    private function createEstimate(array $overrides = []): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-SNAPSHOT',
            'name' => 'Snapshot estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-07-28',
            'base_price_date' => '2026-07-28',
            'total_direct_costs' => 0,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_equipment_costs' => 0,
            'total_amount' => 0,
            'total_amount_with_vat' => 0,
            'vat_rate' => 20,
            'overhead_rate' => 0,
            'profit_rate' => 0,
            'calculation_method' => 'resource',
        ], $overrides));
    }

    private function requestFor(Estimate $estimate): Request
    {
        $user = User::factory()->create([
            'current_organization_id' => $estimate->organization_id,
        ]);

        $this->actingAs($user);

        $request = Request::create(
            "/api/v1/admin/projects/{$estimate->project_id}/estimates/{$estimate->id}/structure",
            'GET'
        );
        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('current_organization_id', $estimate->organization_id);

        return $request;
    }
}
