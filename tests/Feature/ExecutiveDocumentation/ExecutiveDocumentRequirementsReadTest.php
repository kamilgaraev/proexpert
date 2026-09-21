<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsQueryService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Mockery;

final class ExecutiveDocumentRequirementsReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_and_history_reads_are_paginated_and_scoped_to_set(): void
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class, function (\Mockery\MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(static fn (int $organizationId, string $slug): bool => in_array($slug, [
                'executive-documentation', 'project-management', 'contract-management', 'file-management', 'report-templates',
            ], true));
        });
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = \App\Models\Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'created_by' => $context->user->id,
            'set_number' => 'READ-1', 'title' => 'Read test', 'status' => 'draft',
        ]);
        $requirement = ExecutiveDocumentRequirement::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'document_set_id' => $set->id,
            'stage' => 'document_review', 'requirement_key' => 'read', 'title' => 'Read', 'profile_type' => 'hidden_work_act',
            'applicability' => 'required', 'revision' => 1, 'source' => 'test', 'source_revision' => '1', 'rule_snapshot' => [],
            'coverage_scope' => [], 'evidence' => [],
        ]);
        $eventId = DB::table('executive_document_requirement_events')->insertGetId([
            'requirement_id' => $requirement->id, 'document_set_id' => $set->id, 'organization_id' => $context->organization->id,
            'actor_id' => $context->user->id, 'action' => 'created', 'before_snapshot' => null,
            'after_snapshot' => json_encode(['id' => $requirement->id, 'title' => 'Read'], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        $headers = $context->authHeaders();
        $response = $this->withHeaders($headers)->getJson("/api/v1/admin/executive-documentation/sets/{$set->id}/requirements?per_page=1");
        self::assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.composition_revision', $eventId)->assertJsonPath('summary.requirements_total', 1);
        $this->withHeaders($headers)->getJson("/api/v1/admin/executive-documentation/sets/{$set->id}/requirements/history")
            ->assertOk()->assertJsonPath('data.0.after_snapshot.title', 'Read')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.composition_revision', $eventId);
        $payload = ['operation_key' => 'read-replace', 'expected_composition_revision' => $eventId, 'requirements' => [[
            'profile_type' => 'working_drawing_set', 'requirement_key' => 'drawing', 'title' => 'Чертежи',
            'stage' => 'document_review', 'source' => 'Перечень', 'source_revision' => '2',
        ]]];
        $this->withHeaders($headers)->putJson("/api/v1/admin/executive-documentation/sets/{$set->id}/requirements", $payload)->assertOk();
        $this->withHeaders($headers)->putJson("/api/v1/admin/executive-documentation/sets/{$set->id}/requirements", $payload)->assertOk();
        $payload['operation_key'] = 'stale-request';
        $this->withHeaders($headers)->putJson("/api/v1/admin/executive-documentation/sets/{$set->id}/requirements", $payload)->assertStatus(409);
        $current = $set->requirements()->whereNull('superseded_at')->firstOrFail();
        $decision = ['expected_revision' => 1, 'reason' => 'Состав приложений подтверждён перечнем проекта', 'conditions' => ['required_relations' => []]];
        $this->withHeaders($headers)->postJson("/api/v1/admin/executive-documentation/requirements/{$current->id}/conditions", $decision)
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->withHeaders($headers)->postJson("/api/v1/admin/executive-documentation/requirements/{$current->id}/conditions", $decision)
            ->assertOk()->assertJsonPath('data.revision', 2);
    }

    public function test_read_service_hides_a_set_from_another_organization(): void
    {
        $owner = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $set = $this->createSet($owner, true);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(404);
        app(ExecutiveDocumentRequirementsQueryService::class)->active($set, $foreign->user);
    }

    public function test_read_service_rejects_an_unassigned_project(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context, false);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(404);
        app(ExecutiveDocumentRequirementsQueryService::class)->active($set, $context->user);
    }

    public function test_read_service_requires_view_permission(): void
    {
        $context = AdminApiTestContext::create();
        $set = $this->createSet($context, true);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnFalse();
        $this->app->instance(AuthorizationService::class, $authorization);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(403);
        app(ExecutiveDocumentRequirementsQueryService::class)->history($set, $context->user);
    }

    private function createSet(AdminApiTestContext $context, bool $assignProject): ExecutiveDocumentSet
    {
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $project = \App\Models\Project::factory()->create(['organization_id' => $context->organization->id]);
        if ($assignProject) {
            $context->user->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        }

        return ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'READ-'.uniqid(),
            'title' => 'Read test',
            'status' => 'draft',
        ]);
    }
}
