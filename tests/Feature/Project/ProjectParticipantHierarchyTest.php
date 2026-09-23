<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
use Tests\TestCase;

class ProjectParticipantHierarchyTest extends TestCase
{
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
