<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\MeasurementUnit;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentationWorkTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_references_create_executive_work_types_once_per_organization(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/references?project_id={$project->id}")
            ->assertOk()
            ->assertJsonCount(25, 'data.work_types');

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/executive-documentation/references?project_id={$project->id}")
            ->assertOk()
            ->assertJsonCount(25, 'data.work_types');

        $this->assertSame(25, WorkType::query()
            ->where('organization_id', $context->organization->id)
            ->where('category', 'Исполнительная документация')
            ->count());
    }

    public function test_hidden_work_act_requires_own_work_type_and_project_journal_entry(): void
    {
        Storage::fake('s3');

        $context = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
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
            'name' => 'бетонные работы',
            'code' => 'concrete_works',
            'measurement_unit_id' => $unit->id,
            'category' => 'Исполнительная документация',
            'additional_properties' => ['contexts' => ['executive_documentation']],
            'is_active' => true,
        ]);
        $foreignWorkType = WorkType::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'name' => 'чужие работы',
            'code' => 'foreign_works',
            'is_active' => true,
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
            'entry_number' => 3,
            'work_description' => 'Бетонирование фундаментной плиты',
            'status' => 'approved',
            'created_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id,
            'approved_at' => now(),
        ]);
        $foreignJournal = ConstructionJournal::query()->create([
            'organization_id' => $foreignContext->organization->id,
            'project_id' => $foreignProject->id,
            'name' => 'Чужой журнал',
            'journal_number' => 'FOREIGN',
            'start_date' => now()->subDay()->toDateString(),
            'status' => 'active',
            'created_by_user_id' => $foreignContext->user->id,
        ]);
        $foreignEntry = ConstructionJournalEntry::query()->create([
            'journal_id' => $foreignJournal->id,
            'entry_date' => now()->toDateString(),
            'entry_number' => 9,
            'work_description' => 'Чужие работы',
            'status' => 'approved',
            'created_by_user_id' => $foreignContext->user->id,
        ]);

        $setResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/executive-documentation/sets', [
                'project_id' => $project->id,
                'title' => 'Комплект фундамента',
            ]);
        $setResponse->assertCreated();
        $setId = (int) $setResponse->json('data.id');

        $basePayload = [
            'document_type' => 'hidden_work_act',
            'title' => 'Акт скрытых работ по бетонированию',
            'document_date' => now()->toDateString(),
            'copies_count' => 2,
            'form_variant' => 'order_344',
            'journal_entry_id' => $entry->id,
            'profile_data' => [
                'act_number' => 'АСР-1',
                'presented_works' => 'Бетонирование фундаментной плиты',
                'started_at' => now()->subDay()->toDateString(),
                'finished_at' => now()->toDateString(),
                'next_works_permission' => 'Разрешается устройство гидроизоляции',
            ],
            'initial_version' => [
                'version_number' => '1.0',
                'file' => UploadedFile::fake()->createWithContent('hidden-work.pdf', str_repeat('a', 1024)),
            ],
        ];

        $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", $basePayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['work_type_id']);

        $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                ...$basePayload,
                'work_type_id' => $foreignWorkType->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['work_type_id']);

        $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                ...$basePayload,
                'work_type_id' => $workType->id,
                'journal_entry_id' => $foreignEntry->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['journal_entry_id']);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/executive-documentation/sets/{$setId}/documents", [
                ...$basePayload,
                'work_type_id' => $workType->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.document_type', 'hidden_work_act');
        $response->assertJsonPath('data.work_type.id', $workType->id);
        $response->assertJsonPath('data.journal_entry_id', $entry->id);
        $response->assertJsonPath('data.profile_data.act_number', 'АСР-1');
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
