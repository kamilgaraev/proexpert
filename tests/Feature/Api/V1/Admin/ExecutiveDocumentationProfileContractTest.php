<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentationProfileContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_references_expose_regulated_document_profiles_and_project_dictionaries(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $unit = MeasurementUnit::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Кубический метр',
            'short_name' => 'м3',
            'type' => 'work',
        ]);
        $workType = WorkType::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Монолитные работы',
            'code' => 'monolithic',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $location = ProjectLocation::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'location_type' => 'section',
            'name' => 'Оси 1-4',
            'code' => '1-4',
            'path' => 'Фундамент / Оси 1-4',
        ]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Общий журнал работ',
            'journal_number' => 'ОЖР-1',
            'start_date' => now()->subDay()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $entry = ConstructionJournalEntry::query()->create([
            'journal_id' => $journal->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => 7,
            'work_description' => 'Армирование фундаментной плиты',
            'status' => 'approved',
            'created_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'approved_at' => now(),
        ]);
        $volume = $entry->workVolumes()->create([
            'work_type_id' => $workType->id,
            'quantity' => 25,
            'measurement_unit_id' => $unit->id,
        ]);
        CompletedWork::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'work_type_id' => $workType->id,
            'journal_entry_id' => $entry->id,
            'journal_work_volume_id' => $volume->id,
            'user_id' => $context->user->id,
            'quantity' => 25,
            'completion_date' => now()->toDateString(),
            'status' => 'confirmed',
        ]);
        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетон B25',
            'code' => 'B25',
            'measurement_unit_id' => $unit->id,
            'is_active' => true,
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Бетонный завод',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/references?project_id={$project->id}");

        $response->assertOk();
        $profiles = collect($response->json('data.document_profiles'));
        $profileTypes = $profiles->pluck('type')->all();

        $this->assertEqualsCanonicalizing([
            'hidden_work_act',
            'axis_layout_act',
            'geodetic_base_acceptance_act',
            'responsible_structure_act',
            'engineering_network_section_act',
            'construction_control_remark',
            'working_drawing_set',
            'geodetic_scheme',
            'network_result_scheme',
            'system_test_act',
            'inspection_result',
            'incoming_control_document',
            'work_journal',
        ], $profileTypes);

        $hiddenWorkProfile = $profiles->firstWhere('type', 'hidden_work_act');
        $this->assertTrue($hiddenWorkProfile['requires_work_type']);
        $this->assertTrue($hiddenWorkProfile['requires_journal_entry']);
        $this->assertContains('344/пр, приложение 3', $hiddenWorkProfile['regulatory_basis']);
        $this->assertContains('presented_works', collect($hiddenWorkProfile['fields'])->pluck('key')->all());
        $this->assertContains('direct_work_executor', collect($hiddenWorkProfile['signatory_roles'])->pluck('key')->all());

        $workTypes = collect($response->json('data.work_types'));
        $this->assertCount(25, $workTypes);
        $this->assertSame(25, $workTypes->pluck('code')->unique()->count());
        $this->assertTrue($workTypes->contains('name', 'бетонные работы'));

        $this->assertEquals($journal->id, $response->json('data.journals.0.id'));
        $this->assertEquals($entry->id, $response->json('data.journal_entries.0.id'));
        $this->assertStringStartsWith('АСР-', (string) $response->json('data.journal_entries.0.hidden_work_act_defaults.profile_data.act_number'));
        $this->assertStringContainsString('Армирование фундаментной плиты', (string) $response->json('data.journal_entries.0.hidden_work_act_defaults.profile_data.presented_works'));
        $this->assertSame(now()->toDateString(), $response->json('data.journal_entries.0.hidden_work_act_defaults.profile_data.started_at'));
        $this->assertSame(now()->toDateString(), $response->json('data.journal_entries.0.hidden_work_act_defaults.profile_data.finished_at'));
        $this->assertStringContainsString('25.000', (string) $response->json('data.journal_entries.0.hidden_work_act_defaults.profile_data.actual_volume'));
        $this->assertEquals($material->id, $response->json('data.materials.0.id'));
        $this->assertEquals($supplier->id, $response->json('data.suppliers.0.id'));
        $this->assertEquals($location->id, $response->json('data.project_locations.0.id'));
        $this->assertTrue($workTypes->contains('code', 'concrete_works'));
        $this->assertFalse($workTypes->contains('id', $workType->id));
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
