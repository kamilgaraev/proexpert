<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ProjectOrganizationRole;
use App\BusinessModules\Features\BudgetEstimates\Services\ConstructionJournalPayloadService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\Material;
use App\Models\JournalWorkVolume;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalFormOptionsService;
use App\Services\Mobile\MobileConstructionJournalService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\TestCase;

final class ConstructionJournalFormOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
    }

    public function test_resources_keep_normative_units_and_do_not_double_count_projected_children(): void
    {
        [$organization, $user, $project, $journal, $estimate, $item, $unit] = $this->fixture();
        $resourceUnit = MeasurementUnit::query()->firstOrCreate([
            'organization_id' => $organization->id,
            'short_name' => 'т',
        ], [
            'name' => 'Нормативная тонна',
            'type' => 'material',
        ]);
        $material = Material::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Арматура',
            'code' => 'JOURNAL-ARMATURE',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $child = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'parent_work_id' => $item->id,
            'position_number' => '1.1',
            'item_type' => 'material',
            'name' => 'Арматура, проекция',
            'measurement_unit_id' => $unit->id,
            'quantity' => 30,
            'quantity_total' => 30,
        ]);
        $resource = EstimateItemResource::query()->create([
            'estimate_item_id' => $item->id,
            'resource_type' => 'material',
            'name' => 'Арматура нормативная',
            'material_id' => $material->id,
            'measurement_unit_id' => $resourceUnit->id,
            'quantity_per_unit' => 0.3,
            'total_quantity' => 30,
            'represented_by_item_id' => $child->id,
        ]);
        $service = app(ConstructionJournalFormOptionsService::class);
        $options = $service->build($user, $journal);
        $mapped = $options['estimates'][0]['items'][0];

        self::assertCount(1, $options['estimates'][0]['items']);
        self::assertCount(1, $mapped['resources']);
        self::assertSame([
            'id' => $resource->id,
            'resource_type' => 'material',
            'name' => 'Арматура нормативная',
            'measurement_unit_id' => $resourceUnit->id,
            'measurementUnit' => ['id' => $resourceUnit->id, 'name' => $resourceUnit->name, 'short_name' => $resourceUnit->short_name],
            'quantity_per_unit' => 0.3,
            'total_quantity' => 30.0,
            'estimate_item_id' => $child->id,
            'material_id' => $material->id,
        ], $mapped['resources'][0]);
        self::assertSame($mapped, $service->mapEstimateItem($item->fresh(), $journal));
        self::assertSame($options, app(MobileConstructionJournalService::class)->buildEntryFormOptions($user, $journal));
        self::assertSame([], $options['project_materials']);

        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'estimate_id' => $estimate->id,
            'entry_date' => '2026-10-01',
            'entry_number' => 1,
            'work_description' => 'Бетонирование',
            'status' => 'draft',
            'created_by_user_id' => $user->id,
        ]);
        JournalWorkVolume::query()->create([
            'journal_entry_id' => $entry->id,
            'estimate_item_id' => $item->id,
            'quantity' => 5,
            'measurement_unit_id' => $unit->id,
        ]);
        $entry->load(ConstructionJournalPayloadService::ENTRY_RELATIONS);
        $persisted = app(ConstructionJournalPayloadService::class)->mapEntry($entry, $user);
        self::assertSame($mapped, $persisted['workVolumes'][0]['estimateItem']);
    }

    public function test_contract_quantity_is_current_journal_pivot_and_read_does_not_attach_coverage(): void
    {
        [$organization, $user, $project, $journal, $estimate, $item] = $this->fixture();
        $contract = $this->contract($organization, $project, 'CURRENT');
        $otherContract = $this->contract($organization, $project, 'OTHER');
        $journal->update(['contract_id' => $contract->id]);
        ContractEstimateItem::query()->create([
            'contract_id' => $otherContract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id,
            'quantity' => 80,
            'amount' => 80,
        ]);
        $service = app(ConstructionJournalFormOptionsService::class);
        $uncovered = $service->build($user, $journal)['estimates'][0]['items'][0];
        self::assertSame(100.0, $uncovered['estimate_planned_quantity']);
        self::assertNull($uncovered['contract_agreed_quantity']);
        self::assertSame('auto_attach_available', $uncovered['contract_coverage']['contract_coverage_status']);
        self::assertTrue($uncovered['contract_coverage']['can_auto_attach_contract_coverage']);
        self::assertSame(1, ContractEstimateItem::query()->where('estimate_item_id', $item->id)->count());

        ContractEstimateItem::query()->create([
            'contract_id' => $contract->id,
            'estimate_id' => $estimate->id,
            'estimate_item_id' => $item->id,
            'quantity' => 40,
            'amount' => 40,
        ]);
        $covered = $service->build($user, $journal)['estimates'][0]['items'][0];
        self::assertSame(100.0, $covered['estimate_planned_quantity']);
        self::assertSame(40.0, $covered['contract_agreed_quantity']);
        self::assertSame('covered', $covered['contract_coverage']['contract_coverage_status']);
        self::assertSame($contract->id, $covered['contract_coverage']['contract_id']);
        self::assertFalse($covered['contract_coverage']['can_auto_attach_contract_coverage']);

        $journal->update(['contract_id' => null]);
        ContractEstimateItem::query()->where('estimate_item_id', $item->id)->delete();
        $unbound = $service->build($user, $journal)['estimates'][0]['items'][0];
        self::assertNull($unbound['contract_agreed_quantity']);
        self::assertSame('not_covered', $unbound['contract_coverage']['contract_coverage_status']);
    }

    public function test_form_options_scope_estimates_units_and_project_access(): void
    {
        [$organization, $user, $project, $journal, $estimate, $item, $unit] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        foreach ([
            [$organization->id, $project->id, 'draft'],
            [$otherOrganization->id, $project->id, 'approved'],
            [$organization->id, $otherProject->id, 'approved'],
        ] as [$organizationId, $projectId, $status]) {
            Estimate::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'name' => 'Outside scope',
                'number' => 'OUT-'.random_int(100, 999),
                'estimate_date' => '2026-10-01',
                'status' => $status,
            ]);
        }
        $foreignUnit = MeasurementUnit::query()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Чужая единица',
            'short_name' => 'чуж',
            'type' => 'work',
            'is_system' => true,
        ]);
        $service = app(ConstructionJournalFormOptionsService::class);
        $options = $service->build($user, $journal);

        self::assertSame([$estimate->id], array_column($options['estimates'], 'id'));
        self::assertContains($unit->id, array_column($options['measurement_units'], 'id'));
        self::assertNotContains($foreignUnit->id, array_column($options['measurement_units'], 'id'));
        self::assertSame($unit->id, $options['estimates'][0]['items'][0]['measurementUnit']['id']);

        $user->organizations()->updateExistingPivot($organization->id, ['project_access_mode' => 'assigned_projects']);
        $this->expectException(AuthorizationException::class);
        $service->build($user, $journal);
    }

    public function test_known_zero_plan_is_not_replaced_with_price_derived_quantity(): void
    {
        [$organization, $user, $project, $journal, $estimate, $item, $unit] = $this->fixture();
        $zero = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '3',
            'item_type' => 'work',
            'name' => 'Нулевой объём',
            'measurement_unit_id' => $unit->id,
            'quantity' => 0,
            'quantity_total' => null,
            'unit_price' => 5,
            'total_amount' => 100,
        ]);
        $items = app(ConstructionJournalFormOptionsService::class)->build($user, $journal)['estimates'][0]['items'];
        $zeroPayload = collect($items)->firstWhere('id', $zero->id);

        self::assertSame(0.0, $zeroPayload['quantity_total']);
        self::assertSame(0.0, $zeroPayload['estimate_planned_quantity']);
    }

    public function test_shared_assigned_participant_create_options_include_only_project_owner_contracts(): void
    {
        [$organization, $owner, $project] = $this->fixture();
        $actor = $this->sharedAssignedActor($project);
        $active = $this->contract($organization, $project, 'A-CURRENT');
        $completed = $this->contract($organization, $project, 'B-COMPLETED');
        $completed->update(['status' => 'completed']);
        $draft = $this->contract($organization, $project, 'C-DRAFT');
        $draft->update(['status' => 'draft']);
        $otherProject = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $this->contract($organization, $otherProject, 'D-NEIGHBOR');
        $foreignOrganization = Organization::factory()->create(['is_active' => true]);
        $this->contract($foreignOrganization, $project, 'E-FOREIGN');
        $actorOrganization = Organization::query()->findOrFail($actor->current_organization_id);
        $this->contract($actorOrganization, $project, 'F-ACTOR-OWNED');
        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static fn (User $user, string $permission): bool => $permission === 'construction-journal.create');
            $mock->shouldReceive('hasRole')->andReturn(false);
        });

        $options = app(ConstructionJournalFormOptionsService::class)->buildJournalOptions($actor, (int) $project->id);
        self::assertSame([
            ['id' => $active->id, 'number' => 'A-CURRENT', 'contractor_name' => 'A-CURRENT', 'status' => 'active'],
            ['id' => $completed->id, 'number' => 'B-COMPLETED', 'contractor_name' => 'B-COMPLETED', 'status' => 'completed'],
        ], $options['contracts']);
        self::assertSame($options, app(MobileConstructionJournalService::class)->buildJournalFormOptions($actor, $project));

        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.contracts')
            ->assertJsonPath('data.contracts.0.id', $active->id)
            ->assertJsonPath('data.contracts.1.id', $completed->id);
    }

    public function test_create_options_require_assigned_project_and_create_permission(): void
    {
        [$organization, $owner, $project] = $this->fixture();
        $actor = $this->sharedAssignedActor($project);
        $project->users()->detach($actor->id);
        $canCreate = true;
        $this->mock(AuthorizationService::class, static function ($mock) use (&$canCreate): void {
            $mock->shouldReceive('can')->andReturnUsing(static function (User $user, string $permission) use (&$canCreate): bool {
                return $permission === 'construction-journal.view'
                    || ($canCreate && $permission === 'construction-journal.create');
            });
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id)
            ->assertForbidden();

        $project->users()->attach($actor->id, ['is_active' => true]);
        $canCreate = false;
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id)
            ->assertForbidden();
    }

    public function test_edit_only_actor_gets_contracts_only_for_own_active_journal_in_matching_project(): void
    {
        [$organization, $owner, $project, $journal] = $this->fixture();
        $actor = $this->sharedAssignedActor($project);
        $journal->update(['performing_organization_id' => $actor->current_organization_id]);
        $contract = $this->contract($organization, $project, 'EDIT-OPTIONS');
        $otherJournal = ConstructionJournal::query()->create([
            'organization_id' => $organization->id,
            'performing_organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Чужой журнал',
            'journal_number' => 'OTHER-OPTIONS',
            'start_date' => '2026-10-01',
            'status' => 'active',
            'created_by_user_id' => $owner->id,
        ]);
        $otherProject = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $this->mock(AuthorizationService::class, static function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(static fn (User $user, string $permission): bool => $permission === 'construction-journal.edit');
            $mock->shouldReceive('hasRole')->andReturn(false);
        });
        $this->withoutMiddleware();
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id.'&journal_id='.$journal->id)
            ->assertOk()
            ->assertJsonPath('data.contracts.0.id', $contract->id);
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id)
            ->assertForbidden();
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id.'&journal_id='.$otherJournal->id)
            ->assertForbidden();
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$otherProject->id.'&journal_id='.$journal->id)
            ->assertForbidden();

        $journal->update(['status' => 'closed']);
        $this->actingAs($actor, 'api_admin')
            ->getJson('/api/v1/admin/construction-journals/form-options?project_id='.$project->id.'&journal_id='.$journal->id)
            ->assertForbidden();
    }

    private function sharedAssignedActor(Project $project): User
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'assigned_projects']);
        $project->organizations()->attach($organization->id, [
            'is_active' => true,
            'role' => 'child_contractor',
            'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value,
        ]);
        $project->users()->attach($user->id, ['is_active' => true]);

        return $user;
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $unit = MeasurementUnit::query()->where('organization_id', $organization->id)->where('short_name', 'м³')->firstOrFail();
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $organization->id,
            'performing_organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Журнал формы',
            'journal_number' => 'FORM-1',
            'start_date' => '2026-10-01',
            'status' => 'active',
            'created_by_user_id' => $user->id,
        ]);
        $estimate = Estimate::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Смета формы',
            'number' => 'FORM-ESTIMATE',
            'estimate_date' => '2026-10-01',
            'status' => 'approved',
        ]);
        $item = EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => 'work',
            'name' => 'Бетонирование',
            'measurement_unit_id' => $unit->id,
            'quantity' => 10,
            'quantity_total' => 100,
        ]);

        return [$organization, $user, $project, $journal, $estimate, $item, $unit];
    }

    private function contract(Organization $organization, Project $project, string $number): Contract
    {
        $contractor = Contractor::query()->create(['organization_id' => $organization->id, 'name' => $number]);

        return Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => $number,
            'date' => '2026-10-01',
            'subject' => 'Работы',
            'total_amount' => 1000,
            'status' => 'active',
        ]);
    }
}
