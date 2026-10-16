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
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Enums\AuthSessionStatus;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\ContractorType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\Contract\ContractLegalDossierService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDOException;
use ReflectionMethod;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\TestCase;

final class ContractDossierReplayAccessSecurityTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    public function test_manual_dossier_replay_does_not_disclose_document_after_current_access_is_revoked(): void
    {
        Queue::fake();
        $this->enableImmutableAuditWriter();
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $actor = $fixture->addMember([], ['admin.access', 'contracts.edit', 'contracts.view', 'legal_archive.create', 'legal_archive.view']);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $context = $this->httpContext($fixture, $actor);
        $contractor = Contractor::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Dossier replay executor',
            'contact_person' => 'Executor manager', 'email' => 'replay-executor@example.test',
            'inn' => '7707083893', 'contractor_type' => ContractorType::MANUAL->value,
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'contract_side_type' => ContractSideTypeEnum::CONTRACT->value,
            'number' => 'REPLAY-'.Str::uuid(), 'date' => '2026-10-16', 'subject' => 'Project agreement',
            'work_type_category' => ContractWorkTypeCategoryEnum::SMR->value, 'total_amount' => 1000,
            'status' => ContractStatusEnum::DRAFT->value, 'is_fixed_amount' => true,
        ]);
        $input = ['action' => 'create', 'title' => 'Confidential project dossier', 'idempotency_key' => (string) Str::uuid()];
        $url = '/api/v1/admin/projects/'.$project->id.'/contracts/'.$contract->id.'/legal-dossier';
        $created = $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertCreated();
        $documentId = $created->json('data.legal_document.id');
        self::assertIsInt($documentId);
        $before = $this->snapshot();
        $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertOk()
            ->assertJsonPath('data.legal_document.id', $documentId);
        self::assertSame($before, $this->snapshot());
        $role = $this->role($actor);
        $permissions = $role->system_permissions;
        $this->updateRole($actor, ['system_permissions' => array_values(array_diff($permissions, ['legal_archive.view']))]);
        $contractDocumentUrl = '/api/v1/admin/projects/'.$project->id.'/contracts/'.$contract->id.'/documents/'.$documentId;
        $this->withHeaders($context->authHeaders())->getJson($contractDocumentUrl)->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertOk();
        self::assertSame($before, $this->snapshot());
        $this->updateRole($actor, ['system_permissions' => $permissions]);
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => false]);
        self::assertFalse(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor, $project));
        $view = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/legal-archive/documents/'.$documentId);
        self::assertContains($view->status(), [403, 404], $view->getContent());
        $before = $this->snapshot();
        $replayed = $this->withHeaders($context->authHeaders())->postJson($url, $input);
        self::assertContains($replayed->status(), [403, 404], $replayed->getContent());
        self::assertSame($before, $this->snapshot());
        $actor->assignedProjects()->updateExistingPivot($project->id, ['is_active' => true]);
        $document = LegalArchiveDocument::query()->findOrFail($documentId);
        $document->update(['confidentiality_level' => 'restricted']);
        $grant = $this->grantView($fixture, $actor, $document);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/legal-archive/documents/'.$documentId)->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertOk();
        $grant->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id, 'revocation_reason' => 'Access removed'])->save();
        $view = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/legal-archive/documents/'.$documentId);
        self::assertContains($view->status(), [403, 404], $view->getContent());
        $before = $this->snapshot();
        $replayed = $this->withHeaders($context->authHeaders())->postJson($url, $input);
        self::assertContains($replayed->status(), [403, 404], $replayed->getContent());
        self::assertSame($before, $this->snapshot());
        $service = app(ContractLegalDossierService::class);
        $this->assertCollisionAccessDenied($service, $fixture, $actor, $project, $contract, $document, $input);

        $this->grantView($fixture, $actor, $document);
        $this->updateRole($actor, ['system_permissions' => array_values(array_diff($permissions, ['contracts.view']))]);
        self::assertFalse(app(AuthorizationService::class)->forCurrentChecks(true)->can($actor, 'contracts.view', [
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id,
        ]));
        $before = $this->snapshot();
        $replayed = $this->withHeaders($context->authHeaders())->postJson($url, $input);
        self::assertContains($replayed->status(), [403, 404], $replayed->getContent());
        self::assertSame($before, $this->snapshot());
        $this->updateRole($actor, ['system_permissions' => $permissions]);

        foreach (['user', 'organization', 'membership'] as $revoked) {
            if ($revoked === 'user') {
                User::query()->whereKey($actor->id)->update(['is_active' => false]);
            } elseif ($revoked === 'organization') {
                $fixture->organization->update(['is_active' => false]);
            } else {
                $actor->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => false]);
            }
            $before = $this->snapshot();
            $this->assertAccessDenied(fn () => $service->create($actor, $fixture->organization->id, $project->id, $contract->id, $input));
            self::assertSame($before, $this->snapshot());
            User::query()->whereKey($actor->id)->update(['is_active' => true]);
            $fixture->organization->update(['is_active' => true]);
            $actor->organizations()->updateExistingPivot($fixture->organization->id, ['is_active' => true]);
        }
        $before = $this->snapshot();
        $this->withHeaders($context->authHeaders())->postJson($url, $input)->assertOk();
        $recovery = new ReflectionMethod($service, 'resolveConcurrentBinding');
        foreach ([[null, $input['idempotency_key']], [$documentId, null]] as [$expectedDocumentId, $key]) {
            $result = $recovery->invoke($service, $actor, $fixture->organization->id, $project->id, $contract->id, $expectedDocumentId, $key, new QueryException('pgsql', 'synthetic collision', [], new PDOException));
            self::assertSame('replayed', $result->operationResult);
            self::assertSame($documentId, $result->document->id);
        }
        self::assertSame($before, $this->snapshot());

        $otherProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $document->update(['primary_project_id' => $otherProject->id]);
        $before = $this->snapshot();
        $replayed = $this->withHeaders($context->authHeaders())->postJson($url, $input);
        self::assertContains($replayed->status(), [403, 404], $replayed->getContent());
        self::assertSame($before, $this->snapshot());
        $this->assertCollisionAccessDenied($service, $fixture, $actor, $project, $contract, $document, $input);

    }

    public function test_procurement_dossier_replay_requires_current_restricted_document_grant(): void
    {
        Queue::fake();
        $this->enableImmutableAuditWriter();
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts', 'supply-warehouse']);
        $actor = $fixture->addMember(['procurement' => ['procurement.contracts.create', 'procurement.contracts.view']], ['admin.access']);
        $context = $this->httpContext($fixture, $actor);
        $supplier = Supplier::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Dossier replay supplier',
            'email' => 'replay-supplier@example.test', 'is_active' => true,
        ]);
        $request = PurchaseRequest::query()->create([
            'organization_id' => $fixture->organization->id, 'request_number' => 'PR-'.Str::uuid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
        ]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => $fixture->organization->id, 'purchase_request_id' => $request->id,
            'supplier_id' => $supplier->id, 'order_number' => 'PO-'.Str::uuid(),
            'order_date' => now()->toDateString(), 'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 50000, 'currency' => 'RUB', 'delivery_date' => '2026-11-01',
        ]);
        $url = '/api/v1/admin/procurement/purchase-orders/'.$order->id.'/create-contract';
        $created = $this->withHeaders($context->authHeaders())->postJson($url);
        self::assertSame(201, $created->status(), $created->getContent());
        $documentId = $created->json('data.legal_document.id');
        self::assertIsInt($documentId);
        $document = LegalArchiveDocument::query()->findOrFail($documentId);
        self::assertNull($document->primary_project_id);
        $before = $this->snapshot();
        $this->withHeaders($context->authHeaders())->postJson($url)->assertOk()->assertJsonPath('data.legal_document.id', $documentId);
        self::assertSame($before, $this->snapshot());
        $role = $this->role($actor);
        $modulePermissions = $role->module_permissions;
        $this->updateRole($actor, ['module_permissions' => ['procurement' => ['procurement.contracts.create']]]);
        $before = $this->snapshot();
        $denied = $this->withHeaders($context->authHeaders())->postJson($url);
        self::assertContains($denied->status(), [403, 404], $denied->getContent());
        self::assertSame($before, $this->snapshot());
        $newRequest = PurchaseRequest::query()->create([
            'organization_id' => $fixture->organization->id, 'request_number' => 'PR-'.Str::uuid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
        ]);
        $newOrder = $order->refresh()->replicate(['contract_id']);
        $newOrder->forceFill(['purchase_request_id' => $newRequest->id, 'order_number' => 'PO-'.Str::uuid()])->save();
        $newUrl = '/api/v1/admin/procurement/purchase-orders/'.$newOrder->id.'/create-contract';
        $this->withHeaders($context->authHeaders())->postJson($newUrl)->assertCreated();
        $before = $this->snapshot();
        $denied = $this->withHeaders($context->authHeaders())->postJson($newUrl);
        self::assertContains($denied->status(), [403, 404], $denied->getContent());
        self::assertSame($before, $this->snapshot());
        $this->updateRole($actor, ['module_permissions' => $modulePermissions, 'system_permissions' => ['admin.access', 'legal_archive.view']]);
        $document->update(['confidentiality_level' => 'restricted']);
        $grant = $this->grantView($fixture, $actor, $document);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/legal-archive/documents/'.$documentId)->assertOk();
        $this->withHeaders($context->authHeaders())->postJson($url)->assertOk();
        $grant->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id, 'revocation_reason' => 'Access removed'])->save();
        $view = $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/legal-archive/documents/'.$documentId);
        self::assertContains($view->status(), [403, 404], $view->getContent());
        $before = $this->snapshot();
        $replayed = $this->withHeaders($context->authHeaders())->postJson($url);
        self::assertContains($replayed->status(), [403, 404], $replayed->getContent());
        self::assertSame($before, $this->snapshot());
        $contractId = $order->refresh()->contract_id;
        $order->update(['contract_id' => null]);
        $before = $this->snapshot();
        $denied = $this->withHeaders($context->authHeaders())->postJson($url);
        self::assertContains($denied->status(), [403, 404], $denied->getContent());
        self::assertSame($before, $this->snapshot());
        self::assertNull($order->refresh()->contract_id);
        $this->grantView($fixture, $actor, $document);
        $this->withHeaders($context->authHeaders())->postJson($url)->assertOk()->assertJsonPath('data.legal_document.id', $documentId);
        self::assertSame($contractId, $order->refresh()->contract_id);

        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $document->update(['primary_project_id' => $project->id]);
        $before = $this->snapshot();
        $denied = $this->withHeaders($context->authHeaders())->postJson($url);
        self::assertContains($denied->status(), [403, 404], $denied->getContent());
        self::assertSame($before, $this->snapshot());
        $actor->assignedProjects()->attach($project->id, ['role' => 'member', 'is_active' => true]);
        $this->withHeaders($context->authHeaders())->postJson($url)->assertOk()->assertJsonPath('data.legal_document.primary_project_id', $project->id);
        $this->updateRole($actor, ['system_permissions' => ['admin.access']]);
        $before = $this->snapshot();
        $denied = $this->withHeaders($context->authHeaders())->postJson($url);
        self::assertContains($denied->status(), [403, 404], $denied->getContent());
        self::assertSame($before, $this->snapshot());
        $document->update(['confidentiality_level' => 'internal']);
        $before = $this->snapshot();
        $this->withHeaders($context->authHeaders())->postJson($url)->assertOk();
        self::assertSame($before, $this->snapshot());
    }

    private function grantView(AssistantRealAuthorizationFixture $fixture, User $actor, LegalArchiveDocument $document): LegalDocumentAccessGrant
    {
        return LegalDocumentAccessGrant::query()->where('document_id', $document->id)
            ->where('subject_user_id', $actor->id)->whereNull('revoked_at')->first()
            ?? LegalDocumentAccessGrant::query()->create([
                'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
                'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
                'subject_user_id' => $actor->id, 'abilities' => ['view'], 'granted_by_user_id' => $fixture->owner->id,
            ]);
    }

    private function role(User $actor): OrganizationCustomRole
    {
        return OrganizationCustomRole::query()->findOrFail(UserRoleAssignment::query()->where('user_id', $actor->id)->sole()->custom_role_id);
    }

    private function updateRole(User $actor, array $attributes): void
    {
        $role = $this->role($actor);
        $role->update($attributes);
        app(PermissionResolver::class)->clearRolePermissionCache($role->slug, UserRoleAssignment::TYPE_CUSTOM, (int) $role->organization_id, [(int) $actor->id]);
    }

    private function assertCollisionAccessDenied(ContractLegalDossierService $service, AssistantRealAuthorizationFixture $fixture, User $actor, Project $project, Contract $contract, LegalArchiveDocument $document, array $input): void
    {
        $recovery = new ReflectionMethod($service, 'resolveConcurrentBinding');
        $before = $this->snapshot();
        foreach ([[null, $input['idempotency_key']], [$document->id, null]] as [$expectedDocumentId, $key]) {
            $this->assertAccessDenied(fn () => $recovery->invoke($service, $actor, $fixture->organization->id, $project->id, $contract->id, $expectedDocumentId, $key, new QueryException('pgsql', 'synthetic collision', [], new PDOException)));
        }
        self::assertSame($before, $this->snapshot());
    }

    private function assertAccessDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Current document access must be enforced.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function httpContext(AssistantRealAuthorizationFixture $fixture, User $actor): AdminApiTestContext
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $actor->id, 'organization_id' => $fixture->organization->id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Contract dossier replay test', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($actor, 'admin', $sessionUuid, $fixture->organization->id, false);

        return new AdminApiTestContext($fixture->organization, $actor, $tokens->accessToken);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ([
            Contract::class, PurchaseOrder::class, LegalArchiveDocument::class, LegalArchiveDocumentFile::class, LegalArchiveDocumentLink::class,
            LegalArchiveDocumentVersion::class, LegalDocumentAccessGrant::class, LegalDocumentOutboxMessage::class,
            ImmutableAuditEvent::class,
        ] as $model) {
            $query = $model::query();
            if ($model === ImmutableAuditEvent::class) {
                $query->whereIn('domain', ['legal_archive', 'contracts', 'contract']);
            }
            $snapshot[$model] = $query->orderBy('id')->get()->map->getRawOriginal()->all();
        }

        return $snapshot;
    }
}
