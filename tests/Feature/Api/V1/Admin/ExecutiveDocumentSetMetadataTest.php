<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class ExecutiveDocumentSetMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_metadata_can_be_updated_and_optional_values_cleared(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())->patchJson($this->url($set), [
            'title' => 'Комплект отделки стен',
            'stage_name' => 'Отделка',
            'zone_name' => 'Офис',
            'planned_transmittal_date' => '2026-10-13',
        ])->assertOk()->assertJsonPath('data.title', 'Комплект отделки стен')
            ->assertJsonPath('data.planned_transmittal_date', '2026-10-13');

        $this->withHeaders($context->authHeaders())->patchJson($this->url($set), [
            'stage_name' => null,
            'zone_name' => null,
            'planned_transmittal_date' => null,
        ])->assertOk()->assertJsonPath('data.title', 'Комплект отделки стен')
            ->assertJsonPath('data.planned_transmittal_date', null);

        $this->assertDatabaseHas('executive_document_sets', [
            'id' => $set->id,
            'project_id' => $set->project_id,
            'organization_id' => $context->organization->id,
            'status' => 'draft',
            'set_number' => 'ED-TEST-001',
            'stage_name' => null,
            'zone_name' => null,
            'planned_transmittal_date' => null,
        ]);
    }

    public function test_non_draft_sets_cannot_be_changed(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context);
        $this->allowAccess();

        foreach (ExecutiveDocumentStatusEnum::cases() as $status) {
            if ($status === ExecutiveDocumentStatusEnum::DRAFT) {
                continue;
            }
            $set->update(['status' => $status]);
            $this->withHeaders($context->authHeaders())->patchJson($this->url($set), [
                'title' => 'Недопустимая правка',
            ])->assertStatus(409);
            self::assertSame('Исходный комплект', $set->fresh()->title);
        }
    }

    public function test_another_organization_cannot_update_the_set(): void
    {
        $owner = AdminApiTestContext::create();
        $set = $this->createSet($owner);
        $other = AdminApiTestContext::create();
        $this->allowAccess();

        $this->withHeaders($other->authHeaders())->patchJson($this->url($set), [
            'title' => 'Чужая правка',
        ])->assertNotFound();
        self::assertSame('Исходный комплект', $set->fresh()->title);
    }

    public function test_metadata_update_cannot_move_the_set_or_change_its_workflow(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())->patchJson($this->url($set), [
            'title' => 'Недопустимая правка',
            'project_id' => 999,
            'organization_id' => 999,
            'status' => 'approved',
            'set_number' => 'OTHER',
            'approved_list_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'project_id', 'organization_id', 'status', 'set_number', 'approved_list_id',
        ]);
        self::assertSame('Исходный комплект', $set->fresh()->title);
        self::assertSame(ExecutiveDocumentStatusEnum::DRAFT, $set->fresh()->status);
    }

    public function test_edit_permission_is_required(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context);
        $this->allowAccess(false);

        $this->withHeaders($context->authHeaders())->patchJson($this->url($set), [
            'title' => 'Недопустимая правка',
        ])->assertForbidden();
        self::assertSame('Исходный комплект', $set->fresh()->title);
    }

    public function test_invalid_title_and_date_are_rejected(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())->patchJson($this->url($set), [
            'title' => '', 'planned_transmittal_date' => '13.10.2026',
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'planned_transmittal_date']);
        self::assertSame('Исходный комплект', $set->fresh()->title);
    }

    private function createSet(AdminApiTestContext $context): ExecutiveDocumentSet
    {
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);

        return ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'ED-TEST-001',
            'title' => 'Исходный комплект',
            'status' => ExecutiveDocumentStatusEnum::DRAFT,
        ]);
    }

    private function url(ExecutiveDocumentSet $set): string
    {
        return "/api/v1/admin/executive-documentation/sets/{$set->id}";
    }

    private function allowAccess(bool $canEdit = true): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $slug): bool => in_array($slug, [
                    'executive-documentation', 'project-management', 'contract-management',
                    'file-management', 'report-templates',
                ], true)
            );
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($canEdit): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $permission !== 'executive-documentation.edit' || $canEdit
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))->get();
                }
            );
        });
    }
}
