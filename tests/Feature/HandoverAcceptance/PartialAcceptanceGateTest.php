<?php

declare(strict_types=1);

namespace Tests\Feature\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceGate;
use App\Models\Project;
use DomainException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PartialAcceptanceGateTest extends TestCase
{
    public function test_required_pending_checklist_blocks_acceptance_without_signoff(): void
    {
        $context = $this->context();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'title' => 'Проверка обязательных условий приёмки',
            'status' => 'in_progress',
        ]);
        $service = app(HandoverAcceptanceService::class);
        $checklist = $service->addChecklist($scope, [
            'title' => 'Условия приёмки',
            'items' => [
                ['title' => 'Контроль прочности', 'is_required' => true],
                ['title' => 'Дополнительная фотография', 'is_required' => false],
            ],
        ]);
        self::assertFalse(app(HandoverAcceptanceGate::class)->evaluate($scope)['ready']);

        try {
            $service->acceptScope($scope, $context->user->id, null);
            self::fail('Невыполненный обязательный пункт должен блокировать приёмку');
        } catch (DomainException) {
            self::assertSame('in_progress', $scope->fresh()->status);
            self::assertSame(0, $scope->signoffs()->count());
        }
        $service->reviewChecklistItem($checklist->items->first(), $context->user->id, ['status' => 'accepted']);
        self::assertTrue(app(HandoverAcceptanceGate::class)->evaluate($scope)['ready']);
        $accepted = $service->acceptScope($scope, $context->user->id, null);
        self::assertSame('accepted', $accepted->status);
        self::assertSame(1, $accepted->signoffs()->count());
        $this->expectException(DomainException::class);
        $service->reviewChecklistItem($checklist->items->first(), $context->user->id, ['status' => 'rejected']);
    }

    public function test_handover_rechecks_mandatory_checklist_inside_command(): void
    {
        [$context, $scope, $service] = $this->scope();
        $service->addChecklist($scope, ['title' => 'Контроль', 'items' => [['title' => 'Прочность', 'is_required' => true]]]);
        $service->createPackage($scope, $context->user->id, ['title' => 'Передача', 'documents' => []]);
        $scope->update(['status' => 'accepted']);
        self::assertFalse(app(HandoverAcceptanceGate::class)->evaluate($scope, true)['ready']);
        $this->expectException(DomainException::class);
        $service->handoverScope($scope, $context->user->id);
    }

    public function test_linked_executive_set_missing_expected_composition_blocks_acceptance(): void
    {
        [$context, $scope, $service] = $this->scope();
        $set = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'created_by' => $context->user->id, 'set_number' => 'ID-1', 'title' => 'ИД', 'status' => 'draft',
        ]);
        $service->createPackage($scope, $context->user->id, [
            'title' => 'Передача', 'executive_document_set_id' => $set->id, 'documents' => [],
        ]);
        $readiness = app(HandoverAcceptanceGate::class)->evaluate($scope);
        self::assertFalse($readiness['ready']);
        self::assertContains('requirements_not_configured', array_column($readiness['blockers'], 'code'));
        $this->expectException(DomainException::class);
        $service->acceptScope($scope, $context->user->id, null);
    }

    public function test_package_cannot_link_executive_set_of_another_project(): void
    {
        [$context, $scope, $service] = $this->scope();
        $project = Project::factory()->create(['organization_id' => $scope->organization_id]);
        $set = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'set_number' => 'ID-2', 'title' => 'Другой проект', 'status' => 'draft',
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->createPackage($scope, $context->user->id, [
            'title' => 'Передача', 'executive_document_set_id' => $set->id, 'documents' => [],
        ]);
    }

    public function test_foreign_actor_cannot_accept_scope_through_service(): void
    {
        [$context, $scope, $service] = $this->scope();
        $foreign = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $service->acceptScope($scope, $foreign->user->id, null);
    }

    public function test_not_applicable_document_does_not_block_acceptance(): void
    {
        [$context, $scope, $service] = $this->scope();
        $set = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'created_by' => $context->user->id, 'set_number' => 'ID-NA', 'title' => 'ИД', 'status' => 'draft',
        ]);
        $requirements = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService::class);
        $authorization = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        $requirement = $requirements->create($set, [
            'requirement_key' => 'drawing', 'profile_type' => 'working_drawing_set',
            'source' => 'Договор и утверждённый перечень', 'source_revision' => '1',
            'coverage_scope' => ['project_id' => (int) $scope->project_id],
        ], $context->user, $authorization);
        $requirements->markNotApplicable($requirement, $context->user, 'Работы этого раздела исключены договором', $authorization);
        $service->createPackage($scope, $context->user->id, ['title' => 'Передача', 'executive_document_set_id' => $set->id, 'documents' => []]);
        self::assertTrue(app(HandoverAcceptanceGate::class)->evaluate($scope)['ready']);
        $accepted = $service->acceptScope($scope, $context->user->id, null);
        self::assertSame('accepted', $accepted->status);
        self::assertSame('not_applicable', $accepted->signoffs->first()->evidence_snapshot['requirements'][0]['applicability']);
    }

    public function test_canonical_version_is_frozen_at_acceptance_and_new_draft_blocks_handover(): void
    {
        [$context, $scope, $service] = $this->scope();
        \Illuminate\Support\Facades\Storage::fake('s3');
        $set = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id,
            'created_by' => $context->user->id, 'set_number' => 'ID-V', 'title' => 'ИД', 'status' => 'draft',
        ]);
        $document = \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument::query()->create([
            'organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'document_set_id' => $set->id,
            'created_by' => $context->user->id, 'document_type' => 'working_drawing_set', 'title' => 'Рабочие чертежи', 'status' => 'draft',
            'profile_data' => ['drawing_set_code' => 'РД-1', 'drawing_section' => 'АР', 'sheet_list' => ['1'], 'compliance_mark' => 'Соответствует', 'responsible_person' => 'Инженер', 'authority_document' => 'Приказ 1', 'drawing_set_status' => 'review'],
        ]);
        $executive = app(\App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService::class);
        $version = $executive->addVersion($document, $context->user->id, ['version_number' => '1', 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('one.pdf', 'one')]);
        $executive->submit($document->fresh(), $context->user->id, null, $version->id);
        $executive->approve($document->fresh(), $context->user->id, null, $version->id);
        \Tests\Support\ExecutiveDocumentRequirementFixture::cover($set->fresh(), $version->fresh(), $context->user);
        $service->createPackage($scope, $context->user->id, ['title' => 'Передача', 'executive_document_set_id' => $set->id, 'documents' => [[
            'title' => 'РД', 'document_type' => 'working_drawing_set', 'is_required' => true, 'executive_document_version_id' => $version->id,
        ]]]);
        $accepted = $service->acceptScope($scope, $context->user->id, null);
        $signoff = $accepted->signoffs->first();
        self::assertSame($version->id, $signoff->evidence_snapshot['documents'][0]['version_id']);
        $executive->addVersion($document->fresh(), $context->user->id, ['version_number' => '2', 'expected_version_id' => $version->id, 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('two.pdf', 'two')]);
        self::assertFalse(app(HandoverAcceptanceGate::class)->evaluate($accepted, true)['ready']);
        self::assertSame($version->id, $signoff->fresh()->evidence_snapshot['documents'][0]['version_id']);
        $this->expectException(DomainException::class);
        $service->handoverScope($accepted, $context->user->id);
    }

    private function scope(): array
    {
        $context = $this->context();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $scope = AcceptanceScope::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by_user_id' => $context->user->id, 'title' => 'Приёмка', 'status' => 'in_progress',
        ]);

        return [$context, $scope, app(HandoverAcceptanceService::class)];
    }

    private function context(): AdminApiTestContext
    {
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();

        return AdminApiTestContext::create(roleSlug: 'organization_owner');
    }
}
