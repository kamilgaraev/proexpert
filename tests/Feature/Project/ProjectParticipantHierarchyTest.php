<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectParticipantHierarchyTest extends TestCase
{
    public function test_hierarchy_reads_one_fresh_query_with_owner_scopes_and_deleted_names(): void
    {
        $owner = Organization::factory()->create(['is_active' => false]);
        $customer = Organization::factory()->create();
        $child = Organization::factory()->create();
        $inactive = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $otherProject = Project::factory()->create(['organization_id' => $owner->id]);
        $service = app(ProjectParticipantService::class);
        $service->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER, confirmedCapabilities: true);
        $service->attach($project, $child->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);
        $service->attach($project, $inactive->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);
        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $owner->id)->delete();
        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $inactive->id)->update(['is_active' => false]);
        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $child->id)->update(['role_new' => '', 'role' => 'contractor']);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $project->id, 'organization_id' => $customer->id, 'parent_organization_id' => null],
            ['project_id' => $project->id, 'organization_id' => $owner->id, 'parent_organization_id' => $customer->id],
            ['project_id' => $project->id, 'organization_id' => $child->id, 'parent_organization_id' => $owner->id],
            ['project_id' => $project->id, 'organization_id' => $inactive->id, 'parent_organization_id' => $customer->id],
            ['project_id' => $otherProject->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
        ]);
        DB::table('organizations')->where('id', $child->id)->update(['deleted_at' => now()]);
        $this->app->instance('request', Request::create('/api/v1/admin/construction-journals/1/entries'));

        $hierarchy = $this->hierarchyWithOneQuery($service, $project);
        self::assertSame([
            'configured' => true,
            'root_organization_id' => (int) $customer->id,
            'parents' => [
                ['organization_id' => (int) $owner->id, 'parent_organization_id' => (int) $customer->id],
                ['organization_id' => (int) $child->id, 'parent_organization_id' => (int) $owner->id],
            ],
            'participants' => [
                ['organization_id' => (int) $owner->id, 'organization_name' => $owner->name, 'parent_organization_id' => (int) $customer->id],
                ['organization_id' => (int) $customer->id, 'organization_name' => $customer->name, 'parent_organization_id' => null],
                ['organization_id' => (int) $child->id, 'organization_name' => '', 'parent_organization_id' => (int) $owner->id],
            ],
        ], $hierarchy);

        $activity = $this->hierarchyActivityWithOneQuery($service, $project);
        self::assertSame($hierarchy, $activity['hierarchy']);
        self::assertSame([(int) $customer->id], $activity['active_organization_ids']);
        DB::table('organizations')->where('id', $owner->id)->update(['is_active' => true]);
        self::assertSame([(int) $owner->id, (int) $customer->id], $this->hierarchyActivityWithOneQuery($service, $project)['active_organization_ids']);
        DB::table('organizations')->where('id', $customer->id)->update(['is_active' => false]);
        self::assertSame([(int) $owner->id], $this->hierarchyActivityWithOneQuery($service, $project)['active_organization_ids']);
        DB::table('organizations')->where('id', $customer->id)->update(['is_active' => true]);
        self::assertSame($hierarchy, $this->hierarchyActivityWithOneQuery($service, $project)['hierarchy']);

        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $customer->id)->update(['role_new' => ProjectOrganizationRole::OBSERVER->value]);
        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $child->id)->update(['role_new' => ProjectOrganizationRole::CUSTOMER->value]);
        $changedRole = $this->hierarchyWithOneQuery($service, $project);
        self::assertFalse($changedRole['configured']);
        self::assertSame((int) $customer->id, $changedRole['root_organization_id']);
        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $child->id)->update(['role_new' => '', 'role' => 'contractor']);

        DB::table('project_organization')->where('project_id', $project->id)->where('organization_id', $customer->id)->update(['is_active' => false]);
        $fresh = $this->hierarchyWithOneQuery($service, $project);
        self::assertFalse($fresh['configured']);
        self::assertNull($fresh['root_organization_id']);
        self::assertSame([(int) $owner->id, (int) $child->id], array_column($fresh['participants'], 'organization_id'));
        self::assertNull($fresh['participants'][0]['parent_organization_id']);
    }

    public function test_hierarchy_distinguishes_missing_rows_multiple_roots_and_disconnected_cycles(): void
    {
        $owner = Organization::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $service = app(ProjectParticipantService::class);
        $service->attach($project, $first->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);
        $service->attach($project, $second->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);
        $initial = $this->hierarchyWithOneQuery($service, $project);
        self::assertFalse($initial['configured']);
        self::assertNull($initial['root_organization_id']);
        DB::table('project_organization_hierarchy')->insert([
            ['project_id' => $project->id, 'organization_id' => $owner->id, 'parent_organization_id' => null],
            ['project_id' => $project->id, 'organization_id' => $first->id, 'parent_organization_id' => null],
            ['project_id' => $project->id, 'organization_id' => $second->id, 'parent_organization_id' => $first->id],
        ]);
        $multiple = $this->hierarchyWithOneQuery($service, $project);
        self::assertFalse($multiple['configured']);
        self::assertNull($multiple['root_organization_id']);
        DB::table('project_organization_hierarchy')->where('project_id', $project->id)->where('organization_id', $first->id)->update(['parent_organization_id' => $second->id]);
        $cycle = $this->hierarchyWithOneQuery($service, $project);
        self::assertFalse($cycle['configured']);
        self::assertSame((int) $owner->id, $cycle['root_organization_id']);
        DB::table('project_organization_hierarchy')->where('project_id', $project->id)->where('organization_id', $first->id)->update(['parent_organization_id' => $owner->id]);
        self::assertTrue($this->hierarchyWithOneQuery($service, $project)['configured']);
    }

    private function hierarchyWithOneQuery(ProjectParticipantService $service, Project $project): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $hierarchy = $service->getHierarchy($project);
            $selects = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));
            self::assertSame(1, count($selects));

            return $hierarchy;
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function hierarchyActivityWithOneQuery(ProjectParticipantService $service, Project $project): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $facts = $service->getHierarchyWithActivity($project);
            self::assertCount(1, DB::getQueryLog());

            return $facts;
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_it_saves_project_hierarchy_and_exposes_direct_neighbors(): void
    {
        $owner = Organization::factory()->create();
        $customer = Organization::factory()->create();
        $subcontractor = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $service = app(ProjectParticipantService::class);

        $service->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER, confirmedCapabilities: true);
        $service->attach($project, $subcontractor->id, ProjectOrganizationRole::SUBCONTRACTOR, confirmedCapabilities: true);

        self::assertFalse($service->isConfigured((int) $project->id));

        $hierarchy = $service->saveHierarchy($project, (int) $customer->id, [
            ['organization_id' => (int) $owner->id, 'parent_organization_id' => (int) $customer->id],
            ['organization_id' => (int) $subcontractor->id, 'parent_organization_id' => (int) $owner->id],
        ]);

        self::assertTrue($hierarchy['configured']);
        self::assertSame((int) $customer->id, $service->getParent((int) $project->id, (int) $owner->id));
        self::assertSame([(int) $owner->id], $service->getChildren((int) $project->id, (int) $customer->id));
        self::assertSame([(int) $subcontractor->id], $service->getChildren((int) $project->id, (int) $owner->id));
    }

    public function test_it_rejects_cycles_and_incomplete_trees(): void
    {
        $owner = Organization::factory()->create();
        $customer = Organization::factory()->create();
        $participant = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $service = app(ProjectParticipantService::class);

        $service->attach($project, $customer->id, ProjectOrganizationRole::CUSTOMER, confirmedCapabilities: true);
        $service->attach($project, $participant->id, ProjectOrganizationRole::SUBCONTRACTOR, confirmedCapabilities: true);

        try {
            $service->saveHierarchy($project, (int) $customer->id, [
                ['organization_id' => (int) $owner->id, 'parent_organization_id' => (int) $participant->id],
                ['organization_id' => (int) $participant->id, 'parent_organization_id' => (int) $owner->id],
            ]);
            self::fail('Циклическая иерархия должна быть отклонена.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }

        try {
            $service->saveHierarchy($project, (int) $customer->id, []);
            self::fail('Неполная иерархия должна быть отклонена.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    public function test_admin_can_choose_a_non_owner_root_when_project_has_no_customer(): void
    {
        $owner = Organization::factory()->create();
        $participant = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $service = app(ProjectParticipantService::class);
        $service->attach($project, $participant->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);

        self::assertNull($service->getHierarchy($project)['root_organization_id']);

        $hierarchy = $service->saveHierarchy($project, (int) $participant->id, [
            ['organization_id' => (int) $owner->id, 'parent_organization_id' => (int) $participant->id],
        ]);

        self::assertTrue($hierarchy['configured']);
        self::assertSame((int) $participant->id, $hierarchy['root_organization_id']);
        self::assertSame((int) $participant->id, $service->getParent((int) $project->id, (int) $owner->id));
    }

    public function test_it_rejects_foreign_and_inactive_parent_organizations(): void
    {
        $owner = Organization::factory()->create();
        $inactiveParent = Organization::factory()->create();
        $child = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $service = app(ProjectParticipantService::class);
        $service->attach($project, $inactiveParent->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);
        $service->attach($project, $child->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);

        foreach ([(int) $foreign->id, (int) $inactiveParent->id] as $parentOrganizationId) {
            if ($parentOrganizationId === (int) $inactiveParent->id) {
                $service->setActiveState($project, $parentOrganizationId, false);
            }

            $parents = $parentOrganizationId === (int) $foreign->id
                ? [
                    [
                        'organization_id' => (int) $inactiveParent->id,
                        'parent_organization_id' => (int) $owner->id,
                    ],
                    [
                        'organization_id' => (int) $child->id,
                        'parent_organization_id' => $parentOrganizationId,
                    ],
                ]
                : [[
                    'organization_id' => (int) $child->id,
                    'parent_organization_id' => $parentOrganizationId,
                ]];

            try {
                $service->saveHierarchy($project, (int) $owner->id, $parents);
                self::fail('В иерархии нельзя использовать постороннего или отключённого участника.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_open_rfi_blocks_deactivation_of_initiator_and_recipient(): void
    {
        foreach (['initiator', 'recipient'] as $participantSide) {
            $owner = Organization::factory()->create();
            $participant = Organization::factory()->create();
            $other = Organization::factory()->create();
            $project = Project::factory()->create(['organization_id' => $owner->id]);
            $service = app(ProjectParticipantService::class);
            $service->attach($project, $participant->id, ProjectOrganizationRole::OBSERVER, confirmedCapabilities: true);

            ChangeManagementRfi::query()->create([
                'organization_id' => $participantSide === 'initiator' ? $participant->id : $other->id,
                'recipient_organization_id' => $participantSide === 'recipient' ? $participant->id : $other->id,
                'project_id' => $project->id,
                'rfi_number' => 'RFI-'.$participantSide.'-'.$project->id,
                'subject' => 'Вопрос по проекту',
                'question' => 'Требуется уточнение.',
                'addressee_type' => 'organization',
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            try {
                $service->setActiveState($project, (int) $participant->id, false);
                self::fail('Деактивация стороны открытого RFI должна быть запрещена.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(409, $exception->getCode());
            }

            try {
                $service->remove($project, (int) $participant->id);
                self::fail('Удаление стороны открытого RFI должно быть запрещено.');
            } catch (BusinessLogicException $exception) {
                self::assertSame(409, $exception->getCode());
            }

            self::assertDatabaseHas('project_organization', [
                'project_id' => $project->id,
                'organization_id' => $participant->id,
                'is_active' => true,
            ]);
        }
    }
}
