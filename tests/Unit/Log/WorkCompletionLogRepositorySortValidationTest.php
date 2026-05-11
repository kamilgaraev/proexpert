<?php

declare(strict_types=1);

namespace Tests\Unit\Log;

use App\Repositories\Log\WorkCompletionLogRepository;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkCompletionLogRepositorySortValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginated_logs_fall_back_to_completion_date_sorting(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $user = User::factory()->create();

        $workType = WorkType::create([
            'organization_id' => $organization->id,
            'name' => 'Concrete',
        ]);

        DB::table('work_completion_logs')->insert([
            [
                'project_id' => $project->id,
                'work_type_id' => $workType->id,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'quantity' => 1,
                'completion_date' => '2026-04-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project->id,
                'work_type_id' => $workType->id,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'quantity' => 2,
                'completion_date' => '2026-04-03',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $result = (new WorkCompletionLogRepository())->getPaginatedLogs(
            $organization->id,
            15,
            [],
            'completion_date desc',
            'sideways'
        );

        $this->assertSame('2026-04-03', $result->items()[0]->completion_date->format('Y-m-d'));
    }
}
