<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowDecision;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\SignerIdentity;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowService;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class MobileLegalArchiveMutationsTest extends TestCase
{
    use EnablesImmutableAuditWriter;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendNotificationJob::class]);
        $this->enableImmutableAuditWriter();
    }

    public function test_mobile_workflow_action_returns_the_updated_archive_resource(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        [$document, $instance, $step] = $this->workflowDocument($context->user, (int) $context->organization->id, (int) $project->id);

        $response = $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/legal-archive/documents/'.$document->id.'/actions/approve',
            [
                'idempotency_key' => '1f0a1220-05cc-4d21-ae62-8b75d546b8bd',
                'target_step_id' => $step->id,
                'instance_lock_version' => $instance->lock_version,
                'step_lock_version' => $step->lock_version,
                'comment' => 'Проверено в мобильном приложении',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.title', 'Договор для мобильной проверки')
            ->assertJsonPath('data.document_type', 'contract')
            ->assertJsonPath('data.workflow_summary.status', 'approved');
        $this->assertSame(1, LegalWorkflowDecision::query()->where('instance_id', $instance->id)->count());
        $this->assertSame('approved', $step->fresh()->status);
        Queue::assertPushed(SendNotificationJob::class);
    }

    public function test_mobile_workflow_action_returns_conflict_for_stale_step_lock(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        [$document, $instance, $step] = $this->workflowDocument($context->user, (int) $context->organization->id, (int) $project->id);

        $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/legal-archive/documents/'.$document->id.'/actions/approve',
            [
                'idempotency_key' => '4c1b7ad4-75a1-4678-b935-22984710307c',
                'target_step_id' => $step->id,
                'instance_lock_version' => $instance->lock_version,
                'step_lock_version' => $step->lock_version + 1,
            ],
        )->assertStatus(409);

        $this->assertSame(0, LegalWorkflowDecision::query()->where('instance_id', $instance->id)->count());
        $this->assertSame('active', $step->fresh()->status);
    }

    public function test_mobile_workflow_action_is_denied_by_the_mobile_access_gate(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'primary_project_id' => $project->id,
            'title' => 'Закрытый мобильный документ',
            'document_type' => 'contract',
            'status' => 'draft',
            'type_profile_code' => 'contract.work',
            'approval_status' => 'not_started',
            'lock_version' => 0,
        ]);
        Gate::define('access-mobile-app', static fn (User $user): bool => false);

        $this->withHeaders($context->mobileAuthHeaders())->postJson(
            '/api/v1/mobile/legal-archive/documents/'.$document->id.'/actions/approve',
            [
                'idempotency_key' => 'a2af1129-ade5-46c7-b4b4-74bf2ccb4c88',
                'target_step_id' => 1,
                'instance_lock_version' => 0,
                'step_lock_version' => 0,
            ],
        )->assertForbidden();

        $this->assertSame(0, LegalWorkflowInstance::query()->where('document_id', $document->id)->count());
        $this->assertSame(0, LegalWorkflowDecision::query()->where('document_id', $document->id)->count());
    }

    public function test_mobile_paper_original_upload_stores_the_uploaded_pdf_and_returns_created(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        [$document, $version] = $this->documentWithPrimaryPdf(
            $context->user,
            (int) $context->organization->id,
            (int) $project->id,
        );
        $fileService = \Mockery::mock(FileService::class);
        $fileService->shouldReceive('putImmutable')->once()->andReturnUsing(
            static function (string $path, string $body, string $contentType): array {
                return [
                    'path' => $path,
                    'body' => $body,
                    'size' => strlen($body),
                    'sha256' => hash('sha256', $body),
                    'etag' => 'mobile-paper-etag',
                    'content_type' => $contentType,
                    'created' => true,
                ];
            },
        );
        $this->app->instance(FileService::class, $fileService);
        $signatureRequest = app(LegalDocumentSignatureService::class)->createRequest(
            $document,
            $version,
            $context->user,
            'paper',
            new SignerIdentitySet([
                new SignerIdentity('user', (string) $context->user->name, (int) $context->user->id, (int) $context->organization->id),
            ]),
            'mobile-paper-request-001',
            expectedDocumentLockVersion: (int) $document->lock_version,
        );

        $response = $this->withHeaders($context->mobileAuthHeaders())->post(
            '/api/v1/mobile/legal-archive/signature-requests/'.$signatureRequest->id.'/upload-original',
            [
                'file' => UploadedFile::fake()->createWithContent('signed-original.pdf', "%PDF-1.4\nMobile upload test\n%%EOF"),
                'signed_at' => now()->subDay()->toIso8601String(),
                'lock_version' => (int) $document->fresh()->lock_version,
                'idempotency_key' => '1f0a1220-05cc-4d21-ae62-8b75d546b8bd',
            ],
        );

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseCount('legal_document_signatures', 1);
        $this->assertDatabaseCount('legal_signature_artifacts', 1);
        $this->assertSame('paper_original', LegalDocumentSignature::query()->sole()->signature_kind);
        $this->assertSame((int) $version->id, (int) LegalDocumentSignature::query()->sole()->document_version_id);
    }

    /** @return array{LegalArchiveDocument, LegalWorkflowInstance, LegalWorkflowStep} */
    private function workflowDocument(User $actor, int $organizationId, int $projectId): array
    {
        [$document, $version] = $this->documentWithPrimaryPdf($actor, $organizationId, $projectId, 'not_started');
        $template = app(LegalWorkflowTemplateService::class)->createVersion(
            $organizationId,
            'contract',
            'Мобильное согласование',
            [[
                'key' => 'legal_review',
                'label' => 'Юридическая проверка',
                'sequence' => 10,
                'parallel_group' => 'legal',
                'required' => true,
                'policy_key' => 'legal_review',
                'actor_type' => 'user',
                'actor_reference' => (string) $actor->id,
                'due_in_hours' => 24,
            ]],
            $actor,
        );
        $instance = app(LegalDocumentWorkflowService::class)->submit(
            $document->fresh(),
            (int) $version->id,
            $actor,
            new WorkflowOverride(
                idempotencyKey: 'mobile-workflow-submit-001',
                templateId: (int) $template->id,
                expectedDocumentLockVersion: (int) $document->lock_version,
            ),
        );
        $step = $instance->steps()->where('status', 'active')->sole();

        return [$document->fresh(), $instance->refresh(), $step];
    }

    /** @return array{LegalArchiveDocument, LegalArchiveDocumentVersion} */
    private function documentWithPrimaryPdf(
        User $actor,
        int $organizationId,
        int $projectId,
        string $approvalStatus = 'approved',
    ): array {
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $organizationId,
            'primary_project_id' => $projectId,
            'title' => 'Договор для мобильной проверки',
            'document_type' => 'contract',
            'status' => $approvalStatus === 'approved' ? 'approved' : 'draft',
            'type_profile_code' => 'contract.work',
            'lifecycle_status' => $approvalStatus === 'approved' ? 'approved' : 'draft',
            'approval_status' => $approvalStatus,
            'signature_status' => 'unsigned',
            'lock_version' => 0,
        ]);
        $file = LegalArchiveDocumentFile::query()->create([
            'organization_id' => $organizationId,
            'document_id' => $document->id,
            'role' => 'primary',
            'title' => 'Основной документ',
            'sort_order' => 0,
            'is_required' => true,
        ]);
        $version = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => $organizationId,
            'document_id' => $document->id,
            'document_file_id' => $file->id,
            'version_number' => 1,
            'is_current' => true,
            'status' => 'uploaded',
            'processing_status' => 'ready',
            'file_path' => "org-{$organizationId}/mobile-test/source.pdf",
            'original_filename' => 'source.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
            'content_hash' => str_repeat('a', 64),
            'uploaded_by_user_id' => $actor->id,
            'uploaded_at' => now(),
        ]);
        $file->forceFill(['current_version_id' => $version->id])->save();
        $document->forceFill(['current_primary_version_id' => $version->id])->save();

        return [$document->refresh(), $version];
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
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
                },
            );
        });
        $this->mock(PermissionResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasPermission')->andReturn(true);
        });
    }
}
