<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProjectParticipantBatchHierarchyTest extends TestCase
{
    public function test_batch_matches_current_native_hierarchies_in_one_query_and_rechecks_activity(): void
    {
        $owner = Organization::factory()->create(['is_active' => true]);
        $child = Organization::factory()->create(['is_active' => true]);
        $projects = [];
        for ($index = 0; $index < 3; $index++) {
            $project = Project::factory()->create(['organization_id' => $owner->id]);
            $project->organizations()->attach($child->id, ['is_active' => true, 'role' => 'child_contractor', 'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value]);
            $projects[] = $project;
        }
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $projects[0]->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
            ['project_id' => $projects[0]->id, 'organization_id' => $child->id, 'parent_organization_id' => $owner->id],
            ['project_id' => $projects[1]->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
            ['project_id' => $projects[1]->id, 'organization_id' => $child->id, 'parent_organization_id' => null],
        ]);
        $service = app(ProjectParticipantService::class);
        $expected = [];
        foreach ($projects as $project) {
            $expected[(int) $project->id] = $service->getHierarchyWithActivity($project);
        }
        self::assertTrue($expected[$projects[0]->id]['hierarchy']['configured']);
        self::assertFalse($expected[$projects[1]->id]['hierarchy']['configured']);
        self::assertFalse($expected[$projects[2]->id]['hierarchy']['configured']);
        self::assertSame($expected, $this->batchWithOneQuery($service, $projects));
        $child->update(['is_active' => false]);
        DB::table('project_organization')->where('project_id', $projects[1]->id)->where('organization_id', $child->id)->update(['is_active' => false]);
        foreach ($projects as $project) {
            $expected[(int) $project->id] = $service->getHierarchyWithActivity($project);
        }
        self::assertSame($expected, $this->batchWithOneQuery($service, $projects));
        self::assertSame([(int) $owner->id], $expected[$projects[0]->id]['active_organization_ids']);
        self::assertSame([], $service->getHierarchiesWithActivity([]));
    }

    public function test_batch_preserves_customer_roots_non_owner_roots_and_disconnected_cycles(): void
    {
        $owner = Organization::factory()->create(['is_active' => true]);
        $first = Organization::factory()->create(['is_active' => true]);
        $second = Organization::factory()->create(['is_active' => true]);
        $projects = [];
        for ($index = 0; $index < 3; $index++) {
            $project = Project::factory()->create(['organization_id' => $owner->id]);
            foreach ([$first, $second] as $organization) {
                $project->organizations()->attach($organization->id, ['is_active' => true, 'role' => 'child_contractor',
                    'role_new' => $index === 0 && $organization->id === $first->id ? ProjectOrganizationRole::CUSTOMER->value : ProjectOrganizationRole::SUBCONTRACTOR->value]);
            }
            $projects[] = $project;
        }
        foreach ($projects as $index => $project) {
            DB::table('project_organization_hierarchy')->insert([
                ['project_id' => $project->id, 'organization_id' => $owner->id, 'parent_organization_id' => $index === 2 ? null : $first->id],
                ['project_id' => $project->id, 'organization_id' => $first->id, 'parent_organization_id' => $index === 2 ? $second->id : null],
                ['project_id' => $project->id, 'organization_id' => $second->id, 'parent_organization_id' => $index === 2 ? $first->id : $owner->id],
            ]);
        }
        $service = app(ProjectParticipantService::class);
        $expected = [];
        foreach ($projects as $project) {
            $expected[(int) $project->id] = $service->getHierarchyWithActivity($project);
        }
        self::assertTrue($expected[$projects[0]->id]['hierarchy']['configured']);
        self::assertSame((int) $first->id, $expected[$projects[0]->id]['hierarchy']['root_organization_id']);
        self::assertTrue($expected[$projects[1]->id]['hierarchy']['configured']);
        self::assertSame((int) $first->id, $expected[$projects[1]->id]['hierarchy']['root_organization_id']);
        self::assertFalse($expected[$projects[2]->id]['hierarchy']['configured']);
        self::assertSame($expected, $this->batchWithOneQuery($service, $projects));
        $projects[0]->organizations()->updateExistingPivot($first->id, ['role_new' => ProjectOrganizationRole::OBSERVER->value]);
        $projects[0]->organizations()->updateExistingPivot($second->id, ['role_new' => ProjectOrganizationRole::CUSTOMER->value]);
        foreach ($projects as $project) {
            $expected[(int) $project->id] = $service->getHierarchyWithActivity($project);
        }
        self::assertFalse($expected[$projects[0]->id]['hierarchy']['configured']);
        self::assertSame($expected, $this->batchWithOneQuery($service, $projects));
    }

    private function batchWithOneQuery(ProjectParticipantService $service, array $projects): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $service->getHierarchiesWithActivity($projects);
            self::assertCount(1, DB::getQueryLog());

            return $result;
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
