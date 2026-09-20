<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Models\CompletedWork;
use App\Models\Project;
use App\Services\CompletedWork\CompletedWorkRevisionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_correction_records_snapshots_and_moves_fact_to_pending(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'planning_status' => CompletedWork::PLANNING_REQUIRES_SCHEDULE,
            'quantity' => 10,
            'completed_quantity' => 10,
            'price' => 50,
            'total_amount' => 500,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_CONFIRMED,
        ]);

        $response = $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/correction",
            [
                'operation_key' => 'correction-1',
                'expected_version' => CompletedWorkRevisionToken::forWork($work),
                'reason' => 'Исправление подтверждённого объёма по первичному документу',
                'quantity' => 8,
                'completed_quantity' => 8,
            ],
        );

        $response->assertCreated();
        $this->assertDatabaseHas('completed_work_corrections', [
            'completed_work_id' => $work->id,
            'operation_key' => 'correction-1',
        ]);
        $this->assertDatabaseHas('completed_works', [
            'id' => $work->id,
            'quantity' => 8,
            'total_amount' => 400,
            'status' => CompletedWork::STATUS_PENDING,
        ]);
        $correction = \App\Models\CompletedWorkCorrection::query()->where('completed_work_id', $work->id)->firstOrFail();
        self::assertSame('500.00', (string) data_get($correction->snapshot_before, 'total_amount'));
        self::assertSame(400.0, (float) data_get($correction->snapshot_after, 'total_amount'));

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/correction",
            [
                'operation_key' => 'correction-1',
                'expected_version' => CompletedWorkRevisionToken::forWork($work),
                'reason' => 'Исправление подтверждённого объёма по первичному документу',
                'quantity' => 8,
                'completed_quantity' => 8,
            ],
        )->assertCreated();
        $this->assertDatabaseCount('completed_work_corrections', 1);
    }

    public function test_correction_rejects_stale_version_and_negative_quantity(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $work = CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_origin_type' => CompletedWork::ORIGIN_MANUAL,
            'quantity' => 10,
            'completion_date' => '2026-09-20',
            'status' => CompletedWork::STATUS_PENDING,
        ]);

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/correction",
            [
                'operation_key' => 'correction-stale',
                'expected_version' => 'old-version',
                'reason' => 'Исправление с устаревшей версией записи',
                'quantity' => 8,
            ],
        )->assertStatus(409);

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/correction",
            [
                'operation_key' => 'correction-negative',
                'expected_version' => $work->updated_at->toISOString(),
                'reason' => 'Попытка отрицательной корректировки',
                'quantity' => -1,
            ],
        )->assertUnprocessable();
        $this->assertDatabaseMissing('completed_work_corrections', ['completed_work_id' => $work->id]);

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/correction",
            [
                'operation_key' => 'correction-mismatch',
                'expected_version' => CompletedWorkRevisionToken::forWork($work),
                'reason' => 'Проверка расхождения физического объёма',
                'quantity' => 8,
                'completed_quantity' => 7,
            ],
        )->assertUnprocessable();

        $this->withHeaders($context->authHeaders())->postJson(
            "/api/v1/admin/projects/{$project->id}/works/{$work->id}/correction",
            [
                'operation_key' => 'correction-foreign-source',
                'expected_version' => CompletedWorkRevisionToken::forWork($work),
                'reason' => 'Проверка чужого исходного события',
                'quantity' => 8,
                'source_event_id' => '999999',
            ],
        )->assertUnprocessable();
    }
}
