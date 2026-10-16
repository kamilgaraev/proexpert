<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentLink;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\AuthSessionStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\LegalArchive\Files\LegalDocumentScanner;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\Sources\LegalDocumentCreateRequestFingerprint;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class LegalArchiveProjectBindingSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    public function test_create_requires_actor_access_to_the_destination_project_before_domain_writes(): void
    {
        Queue::fake();
        $this->enableImmutableAuditWriter();
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $actor = $fixture->addMember([], ['admin.access', 'legal_archive.create', 'legal_archive.view', 'legal_archive.update']);
        $assigned = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $unassigned = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($assigned->id, ['role' => 'member', 'is_active' => true, 'assigned_at' => now()]);
        $projects = app(UserProjectAccessService::class);
        self::assertTrue($projects->canAccessCurrentOrganizationProject($actor, $assigned));
        self::assertFalse($projects->canAccessCurrentOrganizationProject($actor, $unassigned));
        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, 'legal_archive.create', ['organization_id' => $fixture->organization->id]));
        $service = app(LegalArchiveRegistryService::class);
        $noProject = $service->create($fixture->organization->id, $actor->id, $this->input());
        self::assertNull($noProject->primary_project_id);
        self::assertNull(AuthorizationContext::findProjectContext($assigned->id, $fixture->organization->id));
        $allowed = $service->create($fixture->organization->id, $actor->id, $this->input($assigned->id));
        self::assertSame($assigned->id, $allowed->primary_project_id);
        self::assertNotNull(AuthorizationContext::findProjectContext($assigned->id, $fixture->organization->id));
        $before = $this->snapshot();

        try {
            $service->create($fixture->organization->id, $actor->id, $this->input($unassigned->id));
        } catch (AuthorizationException) {
            self::assertSame($before, $this->snapshot());

            return;
        }

        self::fail('Document creation in an unassigned destination project was allowed');
    }

    public function test_update_checks_destination_and_preserves_omitted_and_null_project_semantics(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $sourceProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $destination = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($sourceProject->id, ['role' => 'member', 'is_active' => true]);
        $service = app(LegalArchiveRegistryService::class);
        $document = $service->create($fixture->organization->id, $actor->id, $this->input($sourceProject->id));
        $initialVersion = (int) $document->lock_version;
        $document = $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $initialVersion, 'title' => 'Metadata without project change',
        ]);
        self::assertSame($sourceProject->id, $document->primary_project_id);
        self::assertSame($initialVersion + 1, $document->lock_version);
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => $destination->id,
            'title' => 'Forbidden move', 'links' => [],
        ]));
        self::assertSame($before, $this->snapshot());
        $actor->assignedProjects()->attach($destination->id, ['role' => 'member', 'is_active' => true]);
        $actor->update(['current_organization_id' => $fixture->foreignOrganization->id]);
        $document = $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => (string) $destination->id,
        ]);
        self::assertSame($destination->id, $document->primary_project_id);
        self::assertSame($fixture->foreignOrganization->id, $actor->fresh()->current_organization_id);
        $document = $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => null,
        ]);
        self::assertNull($document->primary_project_id);
    }

    public function test_project_operation_permissions_are_checked_independently_of_project_membership(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture, ['legal_archive.view']);
        $allowedProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $deniedProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        foreach ([$allowedProject, $deniedProject] as $project) {
            $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        }
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Project document operator',
            'slug' => 'project_document_'.Str::lower(Str::random(12)), 'created_by' => $fixture->owner->id,
            'system_permissions' => ['legal_archive.create', 'legal_archive.update'],
            'module_permissions' => [], 'interface_access' => ['admin'], 'is_active' => true,
        ]);
        UserRoleAssignment::assignRole($actor, $role->slug,
            AuthorizationContext::getProjectContext($allowedProject->id, $fixture->organization->id),
            UserRoleAssignment::TYPE_CUSTOM);
        $authorization = app(AuthorizationService::class);
        self::assertFalse($authorization->canCurrent($actor, 'legal_archive.create', ['organization_id' => $fixture->organization->id]));
        self::assertTrue(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor, $deniedProject));
        $service = app(LegalArchiveRegistryService::class);
        $document = $service->create($fixture->organization->id, $actor->id, $this->input($allowedProject->id), null, true);
        self::assertSame($allowedProject->id, $document->primary_project_id);
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->create($fixture->organization->id, $actor->id, $this->input($deniedProject->id), null, true));
        $this->assertDenied(fn () => $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => $deniedProject->id,
        ]));
        self::assertSame($before, $this->snapshot());
    }

    public function test_project_move_preserves_current_restricted_document_access(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $source = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $target = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->organizations()->updateExistingPivot($fixture->organization->id, ['project_access_mode' => 'all_projects']);
        $service = app(LegalArchiveRegistryService::class);
        $document = $service->create($fixture->organization->id, $fixture->owner->id, [
            ...$this->input($source->id), 'confidentiality_level' => 'restricted',
        ]);
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => $target->id,
        ]));
        self::assertSame($before, $this->snapshot());
        $grant = LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $actor->id, 'abilities' => ['view'], 'granted_by_user_id' => $fixture->owner->id,
        ]);
        $document = $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => $target->id,
        ]);
        self::assertSame($target->id, $document->primary_project_id);
        $grant->forceFill([
            'revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id,
            'revocation_reason' => 'Access revoked before project unbinding',
        ])->save();
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->update($document, $fixture->organization->id, $actor->id, [
            'lock_version' => $document->lock_version, 'primary_project_id' => null,
        ]));
        self::assertSame($before, $this->snapshot());
    }

    public function test_project_binding_requires_current_actor_membership_assignment_and_permission(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->sole();
        $role = OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->sole();
        $service = app(LegalArchiveRegistryService::class);
        $service->create($fixture->organization->id, $actor->id, $this->input($project->id), null, true);
        app(AuthorizationService::class)->can($actor, 'legal_archive.create', ['organization_id' => $fixture->organization->id, 'project_id' => $project->id]);
        foreach (['project_assignment', 'archived_project', 'membership', 'user', 'organization', 'role_assignment', 'permission'] as $revocation) {
            $restore = match ($revocation) {
                'project_assignment' => $this->revokeProject($actor, $project),
                'archived_project' => $this->revoke($project, ['is_archived' => true], ['is_archived' => false]),
                'membership' => $this->revokeMembership($actor, $fixture),
                'user' => $this->revoke($actor->fresh(), ['is_active' => false], ['is_active' => true]),
                'organization' => $this->revoke($fixture->organization, ['is_active' => false], ['is_active' => true]),
                'role_assignment' => $this->revoke($assignment, ['is_active' => false], ['is_active' => true]),
                'permission' => $this->revoke($role, ['system_permissions' => ['admin.access', 'legal_archive.view', 'legal_archive.update']], ['system_permissions' => ['admin.access', 'legal_archive.create', 'legal_archive.view', 'legal_archive.update']]),
            };
            $before = $this->snapshot();
            $this->assertDenied(fn () => $service->create($fixture->organization->id, $actor->id, $this->input($project->id), null, true));
            self::assertSame($before, $this->snapshot(), $revocation);
            $restore();
        }
        self::assertSame($project->id, $service->create($fixture->organization->id, $actor->id, $this->input($project->id), null, true)->primary_project_id);
    }

    public function test_shared_project_integer_string_replay_and_trusted_system_creation_remain_supported(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $actor->organizations()->updateExistingPivot($fixture->organization->id, ['project_access_mode' => 'all_projects']);
        $shared = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $shared->organizations()->attach($fixture->organization->id, ['is_active' => true]);
        $data = [...$this->input(), 'primary_project_id' => (string) $shared->id];
        $fingerprint = LegalDocumentCreateRequestFingerprint::fromRequest($fixture->organization->id, $actor->id, $data, null);
        $service = app(LegalArchiveRegistryService::class);
        $document = $service->create($fixture->organization->id, $actor->id, $data);
        self::assertSame($shared->id, $document->primary_project_id);
        self::assertSame($fingerprint, $document->source_request_fingerprint);
        $before = $this->snapshot();
        self::assertSame($document->id, $service->create($fixture->organization->id, $actor->id, $data)->id);
        self::assertSame($before, $this->snapshot());
        $system = $service->create($fixture->organization->id, null, $this->input($shared->id));
        self::assertNull($system->created_by_user_id);
        self::assertSame($shared->id, $system->primary_project_id);
        $this->assertDenied(fn () => $service->create($fixture->organization->id, null, $this->input(), null, true));
    }

    public function test_invalid_or_foreign_project_ids_are_rejected_without_reinterpreting_the_target(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $service = app(LegalArchiveRegistryService::class);
        $foreign = Project::factory()->create(['organization_id' => $fixture->foreignOrganization->id]);
        $before = $this->snapshot();
        foreach ([0, -1, 1.5, true, '', '1e2', '999999999999999999999999', [$foreign->id], $foreign->id] as $invalidId) {
            try {
                $service->create($fixture->organization->id, $actor->id, [...$this->input(), 'primary_project_id' => $invalidId]);
                self::fail('Invalid project identifier was accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('primary_project_id', $exception->errors());
            }
            self::assertSame($before, $this->snapshot());
        }
    }

    public function test_http_create_and_move_require_access_to_the_destination_project(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $context = $this->httpContext($fixture, $actor);
        $allowedProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $unassigned = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($allowedProject->id, ['role' => 'member', 'is_active' => true]);
        $base = '/api/v1/admin/legal-archive/documents';
        $input = $this->input($allowedProject->id);
        $this->withHeaders($context->authHeaders())->postJson($base, $input)->assertCreated();
        $document = LegalArchiveDocument::query()->where('create_operation_key', $input['create_operation_key'])->sole();
        $before = $this->snapshot();
        $response = $this->withHeaders($context->authHeaders())->postJson($base, $this->input($unassigned->id));
        self::assertContains($response->status(), [403, 404], $response->getContent());
        self::assertSame($before, $this->snapshot());
        $response = $this->withHeaders($context->authHeaders())->patchJson($base.'/'.$document->id, [
            'lock_version' => (int) $document->lock_version, 'primary_project_id' => $unassigned->id,
        ]);
        self::assertContains($response->status(), [403, 404], $response->getContent());
        self::assertSame($before, $this->snapshot());
        $actor->assignedProjects()->attach($unassigned->id, ['role' => 'member', 'is_active' => true]);
        $this->withHeaders($context->authHeaders())->patchJson($base.'/'.$document->id, [
            'lock_version' => (int) $document->lock_version, 'primary_project_id' => (string) $unassigned->id,
        ])->assertOk();
        self::assertSame($unassigned->id, $document->fresh()->primary_project_id);
    }

    public function test_user_upload_intent_requires_file_permissions_while_generated_pdf_persistence_is_preserved(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('upload')->willReturn('org-'.$fixture->organization->id.'/legal-archive/generated.pdf');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean');
        $this->app->instance(FileService::class, $storage);
        $this->app->instance(LegalDocumentScanner::class, $scanner);
        $service = app(LegalArchiveRegistryService::class);
        $service->create($fixture->organization->id, $actor->id, $this->input($project->id));
        $permissionContext = ['organization_id' => $fixture->organization->id, 'project_id' => $project->id];
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'legal_archive.files.upload', $permissionContext));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'legal_archive.versions.create', $permissionContext));
        $before = $this->snapshot();
        $this->assertDenied(fn () => $service->create(
            $fixture->organization->id, $actor->id, $this->input($project->id),
            UploadedFile::fake()->createWithContent('user.pdf', "%PDF-1.7\nuser-upload"), true,
        ));
        self::assertSame($before, $this->snapshot());
        $context = $this->httpContext($fixture, $actor);
        $this->withHeaders($context->authHeaders())->post('/api/v1/admin/legal-archive/documents', [
            ...$this->input($project->id), 'requires_upload_permissions' => false, 'requires_user_permissions' => false,
            'file' => UploadedFile::fake()->createWithContent('forged-mode.pdf', "%PDF-1.7\nforged-mode"),
        ])->assertForbidden();
        self::assertSame($before, $this->snapshot());
        $generated = $service->create(
            $fixture->organization->id, $actor->id, $this->input($project->id),
            UploadedFile::fake()->createWithContent('generated.pdf', "%PDF-1.7\nserver-generated"),
        );
        self::assertSame('completed', $generated->source_create_status);
        self::assertSame(1, $generated->files()->count());
        self::assertSame(1, $generated->versions()->count());
        self::assertSame('ready', $generated->currentVersion->processing_status);
    }

    public function test_trusted_document_creator_preserves_contract_roles_without_user_archive_create(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $creator = app(\App\Services\Contract\ContractDossierDocumentCreator::class);
        self::assertInstanceOf(LegalArchiveRegistryService::class, $creator);
        foreach (['parent_administrator' => 'contracts.create', 'project_manager' => 'contracts.edit'] as $roleSlug => $permission) {
            $actor = $this->contractActor($fixture, $project, $roleSlug);
            $context = ['organization_id' => $fixture->organization->id, 'project_id' => $project->id];
            self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, $permission, $context));
            self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'legal_archive.create', $context));
            $document = $creator->create($fixture->organization->id, $actor->id, $this->input($project->id));
            self::assertSame($project->id, $document->primary_project_id);
            $before = $this->snapshot();
            $this->assertDenied(fn () => $creator->create(
                $fixture->organization->id, $actor->id, $this->input($project->id), null, true,
            ));
            self::assertSame($before, $this->snapshot());
            $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
            $this->assertDenied(fn () => $creator->create($fixture->organization->id, $actor->id, $this->input($project->id)));
            self::assertSame($before, $this->snapshot());
        }
    }

    public function test_contract_http_creation_preserves_contract_permissions_and_manual_archive_restrictions(): void
    {
        $fixture = $this->fixture();
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor = $this->contractActor($fixture, $project, 'parent_administrator');
        $context = $this->httpContext($fixture, $actor);
        $external = \App\Models\Contractor::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'External executor',
            'contact_person' => 'Executor Manager', 'email' => 'executor@example.test',
            'inn' => '7707083893', 'contractor_type' => \App\Enums\ContractorType::MANUAL->value,
        ]);
        $input = [
            'project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'expense',
            'contractor_id' => $external->id, 'number' => 'ROLE-CONTRACT', 'date' => '2026-09-19',
            'subject' => 'Работы по договору', 'base_amount' => 1000, 'total_amount' => 1000,
            'is_fixed_amount' => true, 'status' => 'draft', 'idempotency_key' => 'role-contract-creation',
        ];
        $permissions = ['organization_id' => $fixture->organization->id, 'project_id' => $project->id];
        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor, 'contracts.create', $permissions));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor, 'legal_archive.create', $permissions));
        $url = '/api/v1/admin/projects/'.$project->id.'/contracts';
        $response = $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertCreated();
        $contract = \App\Models\Contract::query()->findOrFail($response->json('data.id'));
        self::assertNotNull($contract->legal_archive_document_id);
        $document = LegalArchiveDocument::query()->findOrFail($contract->legal_archive_document_id);
        self::assertSame($project->id, $document->primary_project_id);
        $before = $this->snapshot();
        $this->withHeaders($context->authHeaders())->postJson($url, $input)
            ->assertOk()->assertJsonPath('data.id', $contract->id);
        self::assertSame($before, $this->snapshot());
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/legal-archive/documents', $this->input($project->id))
            ->assertForbidden();
        self::assertSame($before, $this->snapshot());
        $manager = $this->contractActor($fixture, $project, 'project_manager');
        $managerContext = $this->httpContext($fixture, $manager);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($manager, 'contracts.edit', $permissions));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($manager, 'legal_archive.create', $permissions));
        $this->withHeaders($managerContext->authHeaders())->postJson($url.'/'.$contract->id.'/legal-dossier', [
            'action' => 'create', 'title' => 'Ручное досье', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        self::assertSame($before, $this->snapshot());
    }

    public function test_http_create_replay_uses_the_current_document_project_instead_of_the_original_input(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $context = $this->httpContext($fixture, $actor);
        $originalProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $currentProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($originalProject->id, ['role' => 'member', 'is_active' => true]);
        $input = $this->input($originalProject->id);
        $url = '/api/v1/admin/legal-archive/documents';
        $created = $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertCreated();
        $document = LegalArchiveDocument::query()->findOrFail($created->json('data.id'));
        app(LegalArchiveRegistryService::class)->update($document, $fixture->organization->id, $fixture->owner->id, [
            'lock_version' => (int) $document->lock_version, 'primary_project_id' => $currentProject->id,
            'title' => 'Confidential current project document',
        ]);
        $view = $this->withHeaders($context->authHeaders())->getJson($url.'/'.$document->id);
        self::assertContains($view->status(), [403, 404], $view->getContent());
        $before = $this->snapshot();
        $replayed = $this->withHeaders($context->authHeaders())->postJson($url, $input);
        self::assertContains($replayed->status(), [403, 404], $replayed->getContent());
        self::assertSame($before, $this->snapshot());
        $actor->assignedProjects()->updateExistingPivot($originalProject->id, ['is_active' => false]);
        $actor->assignedProjects()->attach($currentProject->id, ['role' => 'member', 'is_active' => true]);
        $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertCreated()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.primary_project_id', $currentProject->id);
        self::assertSame($before, $this->snapshot());
    }

    public function test_http_manual_and_source_recovery_authorize_current_document_before_lease_or_response(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $context = $this->httpContext($fixture, $actor);
        $url = '/api/v1/admin/legal-archive/documents';
        foreach ([false, true] as $sourceBound) {
            $original = Project::factory()->create(['organization_id' => $fixture->organization->id]);
            $current = Project::factory()->create(['organization_id' => $fixture->organization->id]);
            $actor->assignedProjects()->attach($original->id, ['role' => 'member', 'is_active' => true]);
            $input = $this->input($original->id);
            if ($sourceBound) {
                $input = [...$input, 'source_type' => 'project', 'source_id' => $original->id, 'source_idempotency_key' => (string) Str::uuid()];
            }
            $created = $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertCreated();
            $document = LegalArchiveDocument::query()->findOrFail($created->json('data.id'));
            app(LegalArchiveRegistryService::class)->update($document, $fixture->organization->id, $fixture->owner->id, [
                'lock_version' => (int) $document->lock_version, 'primary_project_id' => $current->id,
                'title' => 'Current confidential recovery content',
            ]);
            foreach (['completed', 'pending', 'expired', 'failed'] as $state) {
                $this->setCreateState($document, $state);
                $before = $this->snapshot();
                $response = $this->withHeaders($context->authHeaders())->postJson($url, $input);
                self::assertContains($response->status(), [403, 404], $response->getContent());
                self::assertSame($before, $this->snapshot(), $state);
            }
            $actor->assignedProjects()->updateExistingPivot($original->id, ['is_active' => false]);
            $actor->assignedProjects()->attach($current->id, ['role' => 'member', 'is_active' => true]);
            $this->setCreateState($document, 'pending');
            $before = $this->snapshot();
            $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertStatus(202)
                ->assertJsonPath('data.id', $document->id)->assertJsonPath('data.primary_project_id', $current->id);
            self::assertSame($before, $this->snapshot());
            foreach (['expired', 'failed'] as $state) {
                $recoveryInput = [...$input, 'create_operation_key' => (string) Str::uuid()];
                if ($sourceBound) {
                    $recoverySource = Project::factory()->create(['organization_id' => $fixture->organization->id]);
                    $recoveryInput = [...$recoveryInput, 'source_id' => $recoverySource->id, 'source_idempotency_key' => (string) Str::uuid()];
                }
                $recovery = $document->refresh()->replicate();
                $recovery->forceFill([
                    'create_operation_id' => (string) Str::uuid(), 'create_operation_key' => $recoveryInput['create_operation_key'],
                    'source_id' => $recoveryInput['source_id'] ?? null,
                    'source_idempotency_key' => $recoveryInput['source_idempotency_key'] ?? null,
                    'source_request_fingerprint' => LegalDocumentCreateRequestFingerprint::fromRequest($fixture->organization->id, $actor->id, $recoveryInput, null),
                ])->save();
                $this->setCreateState($recovery, $state);
                $attempts = $recovery->source_create_attempt_count;
                $recovered = $this->withHeaders($context->authHeaders())->postJson($url, $recoveryInput);
                self::assertSame(201, $recovered->status(), $state.': '.$recovered->getContent());
                $recovered
                    ->assertJsonPath('data.id', $recovery->id)->assertJsonPath('data.primary_project_id', $current->id)
                    ->assertJsonPath('data.title', 'Current confidential recovery content');
                self::assertSame('completed', $recovery->refresh()->source_create_status);
                self::assertSame($attempts + 1, $recovery->source_create_attempt_count);
            }
            $before = $this->snapshot();
            $this->withHeaders($context->authHeaders())->postJson($url, [...$input, 'title' => 'Different command'])->assertStatus(422);
            self::assertSame($before, $this->snapshot());
        }
    }

    public function test_replay_requires_current_view_permission_and_restricted_document_grant(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture);
        $input = [...$this->input(), 'confidentiality_level' => 'restricted'];
        $service = app(LegalArchiveRegistryService::class);
        $document = $service->create($fixture->organization->id, $actor->id, $input, null, true);
        $assignment = UserRoleAssignment::query()->where('user_id', $actor->id)->sole();
        $role = OrganizationCustomRole::query()->where('slug', $assignment->role_slug)->sole();
        $grant = LegalDocumentAccessGrant::query()->where('document_id', $document->id)->where('subject_user_id', $actor->id)->sole();
        self::assertSame($document->id, $service->create($fixture->organization->id, $actor->id, $input, null, true)->id);
        foreach (['completed', 'pending', 'expired', 'failed'] as $state) {
            $this->setCreateState($document, $state);
            $role->update(['system_permissions' => ['admin.access', 'legal_archive.create', 'legal_archive.update']]);
            $before = $this->snapshot();
            $this->assertDenied(fn () => $service->create($fixture->organization->id, $actor->id, $input, null, true));
            self::assertSame($before, $this->snapshot());
            $role->update(['system_permissions' => ['admin.access', 'legal_archive.create', 'legal_archive.view', 'legal_archive.update']]);
            $grant->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id, 'revocation_reason' => 'Access removed'])->save();
            $before = $this->snapshot();
            $this->assertDenied(fn () => $service->create($fixture->organization->id, $actor->id, $input, null, true));
            self::assertSame($before, $this->snapshot());
            $grant = LegalDocumentAccessGrant::query()->create([
                'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
                'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
                'subject_user_id' => $actor->id, 'abilities' => ['view'], 'granted_by_user_id' => $fixture->owner->id,
            ]);
        }
    }

    public function test_new_http_document_can_be_created_with_create_permission_without_view_permission(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture, ['legal_archive.create']);
        $context = $this->httpContext($fixture, $actor);
        $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/legal-archive/documents', $this->input())
            ->assertCreated();
    }

    public function test_upload_failure_responses_recheck_project_after_document_processing(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture, ['legal_archive.create', 'legal_archive.view', 'legal_archive.files.upload', 'legal_archive.versions.create']);
        $context = $this->httpContext($fixture, $actor);
        $original = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $current = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($original->id, ['role' => 'member', 'is_active' => true]);
        $input = [];
        $mode = '';
        $move = static function () use (&$input, $current): void {
            LegalArchiveDocument::query()->where('create_operation_key', $input['create_operation_key'])
                ->update(['primary_project_id' => $current->id, 'title' => 'Confidential processing response']);
        };
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(2))->method('upload')->willReturnCallback(static function () use (&$mode, $move, $fixture): string {
            if ($mode === 'storage') {
                $move();
                throw new \RuntimeException('Storage unavailable');
            }

            return 'org-'.$fixture->organization->id.'/legal-archive/recheck.pdf';
        });
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean')->willReturnCallback(static function () use ($move): void {
            $move();
            throw new \RuntimeException('legal_document_scanner_unavailable');
        });
        $this->app->instance(FileService::class, $storage);
        $this->app->instance(LegalDocumentScanner::class, $scanner);
        foreach (['storage', 'scanner'] as $mode) {
            $input = $this->input($original->id);
            $response = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/legal-archive/documents', [
                ...$input, 'file' => UploadedFile::fake()->createWithContent('response.pdf', "%PDF-1.7\nresponse-check"),
            ]);
            self::assertContains($response->status(), [403, 404], $response->getContent());
            self::assertStringNotContainsString('Confidential processing response', $response->getContent());
        }
    }

    public function test_new_upload_failure_preserves_recovery_response_without_view_permission(): void
    {
        $fixture = $this->fixture();
        $actor = $this->actor($fixture, ['legal_archive.create', 'legal_archive.files.upload', 'legal_archive.versions.create']);
        $context = $this->httpContext($fixture, $actor);
        $mode = '';
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(2))->method('upload')->willReturnCallback(static function () use (&$mode, $fixture): string {
            if ($mode === 'storage') {
                throw new \RuntimeException('Storage unavailable');
            }

            return 'org-'.$fixture->organization->id.'/legal-archive/new-create.pdf';
        });
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean')->willThrowException(new \RuntimeException('legal_document_scanner_unavailable'));
        $this->app->instance(FileService::class, $storage);
        $this->app->instance(LegalDocumentScanner::class, $scanner);
        foreach (['storage', 'scanner'] as $mode) {
            $response = $this->withHeaders($context->authHeaders())->post('/api/v1/admin/legal-archive/documents', [
                ...$this->input(), 'file' => UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.7\nnew-create"),
            ])->assertStatus(202)->assertJsonPath('meta.operation_result', 'document_create_failed');
            self::assertIsInt($response->json('meta.retry_document_id'));
            self::assertNotEmpty($response->json('meta.operation_id'));
            self::assertSame($response->json('data.id'), $response->json('meta.retry_document_id'));
        }
    }

    private function setCreateState(LegalArchiveDocument $document, string $state): void
    {
        $pending = in_array($state, ['pending', 'expired'], true);
        $document->refresh()->forceFill([
            'source_create_status' => $pending ? 'pending' : $state,
            'source_create_attempt_token' => $pending ? (string) Str::uuid() : null,
            'source_create_lease_expires_at' => $pending ? ($state === 'expired' ? now()->subMinute() : now()->addMinutes(30)) : null,
            'source_create_retry_action' => $state === 'completed' ? null : 'retry_finalize',
            'source_create_failure_fingerprint' => $state === 'failed' ? hash('sha256', 'Recovery security fixture') : null,
            'source_create_failed_at' => $state === 'failed' ? now() : null,
        ])->save();
    }

    private function contractActor(AssistantRealAuthorizationFixture $fixture, Project $project, string $roleSlug): User
    {
        $actor = $fixture->addMember([], ['admin.access']);
        $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        UserRoleAssignment::assignRole($actor, $roleSlug,
            AuthorizationContext::getProjectContext($project->id, $fixture->organization->id),
            UserRoleAssignment::TYPE_SYSTEM);

        return $actor;
    }

    private function httpContext(AssistantRealAuthorizationFixture $fixture, User $actor): AdminApiTestContext
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $fixture->organization->id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Project binding security test', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, (int) $fixture->organization->id, false);

        return new AdminApiTestContext($fixture->organization, $actor, $tokens->accessToken);
    }

    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake();
        $this->enableImmutableAuditWriter();

        return AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
    }

    private function actor(AssistantRealAuthorizationFixture $fixture, array $permissions = ['legal_archive.create', 'legal_archive.view', 'legal_archive.update']): User
    {
        return $fixture->addMember([], ['admin.access', ...$permissions]);
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unauthorized project binding was allowed');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function revoke(\Illuminate\Database\Eloquent\Model $model, array $attributes, array $restore): \Closure
    {
        $model->update($attributes);

        return static fn () => $model->update($restore);
    }

    private function revokeProject(User $actor, Project $project): \Closure
    {
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);

        return static fn () => $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
    }

    private function revokeMembership(User $actor, AssistantRealAuthorizationFixture $fixture): \Closure
    {
        $actor->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);

        return static fn () => $actor->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => true]);
    }

    private function input(?int $projectId = null): array
    {
        return [
            'title' => 'Project binding security fixture', 'document_type' => 'contract',
            'primary_project_id' => $projectId, 'create_operation_key' => (string) Str::uuid(),
        ];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ([
            LegalArchiveDocument::class, LegalArchiveDocumentFile::class, LegalArchiveDocumentLink::class, LegalArchiveDocumentVersion::class,
            LegalDocumentAccessGrant::class, ImmutableAuditEvent::class, LegalDocumentOutboxMessage::class,
        ] as $model) {
            $query = $model::query();
            if ($model === ImmutableAuditEvent::class) {
                $query->where('domain', 'legal_archive');
            }
            $snapshot[$model] = $query->orderBy('id')->get()->map->getRawOriginal()->all();
        }

        return $snapshot;
    }
}
