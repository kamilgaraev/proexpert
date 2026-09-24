<?php

declare(strict_types=1);

namespace Tests\Feature\CompletedWork;

use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Services\CompletedWork\CompletedWorkFormOptionsService;
use App\Services\Project\ProjectContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CompletedWorkFormOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_contractor_only_receives_owner_references_related_to_its_organization_and_project(): void
    {
        $owner = AdminApiTestContext::create();
        $participant = AdminApiTestContext::create();
        $other = Organization::factory()->verified()->create();
        $otherUser = User::factory()->create(['current_organization_id' => $other->id]);
        $other->users()->attach($otherUser->id, ['is_active' => true]);
        $project = Project::factory()->create(['organization_id' => $owner->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $owner->organization->id]);

        $project->organizations()->attach($participant->organization->id, [
            'role' => ProjectOrganizationRole::CONTRACTOR->value,
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
            'added_by_user_id' => $owner->user->id,
        ]);
        $project->organizations()->attach($other->id, [
            'role' => ProjectOrganizationRole::CONTRACTOR->value,
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
            'added_by_user_id' => $owner->user->id,
        ]);
        $project->users()->syncWithoutDetaching([
            $participant->user->id => ['role' => 'member', 'is_active' => true],
        ]);

        $workType = WorkType::query()->create([
            'organization_id' => $owner->organization->id,
            'name' => 'Проектный вид работ',
            'is_active' => true,
        ]);
        WorkType::query()->create([
            'organization_id' => $other->id,
            'name' => 'Чужой вид работ',
            'is_active' => true,
        ]);

        $ownContractor = Contractor::query()->create([
            'organization_id' => $owner->organization->id,
            'source_organization_id' => $participant->organization->id,
            'name' => 'Участник проекта',
        ]);
        $foreignContractor = Contractor::query()->create([
            'organization_id' => $owner->organization->id,
            'source_organization_id' => $other->id,
            'name' => 'Другой подрядчик',
        ]);
        $contract = $this->contract($owner->organization, $project, $ownContractor, 'OWN-1');
        $this->contract($owner->organization, $project, $foreignContractor, 'FOREIGN-1');
        $this->contract($owner->organization, $otherProject, $ownContractor, 'OTHER-PROJECT-1');

        $this->mock(AuthorizationService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });

        $context = app(ProjectContextService::class)->getContext($project, $participant->organization);
        $options = app(CompletedWorkFormOptionsService::class)->forProject($project, $participant->user, $context);

        self::assertSame([$workType->id], array_column($options['work_types'], 'id'));
        self::assertSame([$ownContractor->id], array_column($options['contractors'], 'id'));
        self::assertSame([$contract->id], array_column($options['contracts'], 'id'));
        self::assertContains($owner->user->id, array_column($options['users'], 'id'));
        self::assertContains($participant->user->id, array_column($options['users'], 'id'));
        self::assertNotContains($otherUser->id, array_column($options['users'], 'id'));

        $ownerContext = app(ProjectContextService::class)->getContext($project, $owner->organization);
        $ownerOptions = app(CompletedWorkFormOptionsService::class)->forProject($project, $owner->user, $ownerContext);
        self::assertContains($participant->user->id, array_column($ownerOptions['users'], 'id'));

        try {
            app(CompletedWorkFormOptionsService::class)->forProject($otherProject, $participant->user, $context);
            self::fail('A participant cannot reuse its context for another project.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }
    }

    private function contract(Organization $owner, Project $project, Contractor $contractor, string $number): Contract
    {
        return Contract::query()->create([
            'organization_id' => $owner->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => $number,
            'date' => '2026-09-24',
            'status' => 'draft',
            'total_amount' => 1000,
        ]);
    }
}
