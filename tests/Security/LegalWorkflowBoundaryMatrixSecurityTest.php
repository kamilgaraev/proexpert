<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Project;
use App\Models\User;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\LegalArchive\Workflow\LegalWorkflowPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class LegalWorkflowBoundaryMatrixSecurityTest extends TestCase
{
    private const PERMISSIONS = [
        LegalWorkflowPermissions::VIEW, LegalWorkflowPermissions::SUBMIT,
        LegalWorkflowPermissions::APPROVE, LegalWorkflowPermissions::REJECT,
        LegalWorkflowPermissions::RETURN, LegalWorkflowPermissions::REASSIGN,
        LegalWorkflowPermissions::CANCEL,
    ];

    public function test_internal_confidentiality_project_membership_and_role_grants_apply_to_every_permission(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember([], self::PERMISSIONS);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        $foreignProject = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        AuthorizationContext::getProjectContext($project->id, $fixture->organization->id);
        AuthorizationContext::getProjectContext($foreignProject->id, $fixture->organization->id);
        $project->users()->attach($actor->id, ['is_active' => true]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $fixture->organization->id, 'name' => 'Document access role',
            'slug' => 'legal_access_'.Str::lower(Str::random(12)), 'system_permissions' => [],
            'module_permissions' => [], 'interface_access' => ['admin'], 'is_active' => true,
            'created_by' => $fixture->owner->id,
        ]);
        $roleAssignment = UserRoleAssignment::query()->create([
            'user_id' => $actor->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext($fixture->organization->id)->id,
            'assigned_by' => $fixture->owner->id, 'is_active' => true,
        ]);
        $cases = [];
        foreach (['restricted', 'secret'] as $level) {
            $allowed = $this->document($fixture, $project, $level);
            $this->grant($fixture, $allowed, $actor);
            $cases[] = [$allowed, true];
            $cases[] = [$this->document($fixture, $project, $level), false];
            $revoked = $this->document($fixture, $project, $level);
            $this->grant($fixture, $revoked, $actor, [
                'revoked_at' => now(), 'revoked_by_user_id' => $fixture->owner->id,
                'revocation_reason' => 'Revoked document access',
            ]);
            $cases[] = [$revoked, false];
            $expired = $this->document($fixture, $project, $level);
            $this->grant($fixture, $expired, $actor, [
                'created_at' => now()->subDay(), 'expires_at' => now()->subMinute(),
            ]);
            $cases[] = [$expired, false];
            $inaccessible = $this->document($fixture, $foreignProject, $level);
            $this->grant($fixture, $inaccessible, $actor);
            $cases[] = [$inaccessible, false];
            $roleDocument = $this->document($fixture, $project, $level);
            $this->grant($fixture, $roleDocument, $actor, [
                'subject_kind' => 'internal_role', 'subject_user_id' => null, 'subject_role_slug' => $role->slug,
            ]);
            $cases[] = [$roleDocument, true];
            $selfIssued = $this->document($fixture, $project, $level);
            $this->grant($fixture, $selfIssued, $actor, [
                'subject_kind' => 'internal_role', 'subject_user_id' => null, 'subject_role_slug' => $role->slug,
                'granted_by_user_id' => $actor->id,
            ]);
            $cases[] = [$selfIssued, false];
        }
        $authorization = app(LegalWorkflowAuthorization::class);
        $documents = collect(array_column($cases, 0));
        $matrix = $authorization->forMany($actor, $documents, self::PERMISSIONS);
        foreach ($cases as [$document, $allowed]) {
            foreach (self::PERMISSIONS as $permission) {
                self::assertSame($allowed, $authorization->can($actor, $document, $permission), $document->id.':'.$permission);
                self::assertSame($allowed, $matrix[$document->id][$permission], 'batch '.$document->id.':'.$permission);
            }
        }

        $roleAssignment->forceFill(['is_active' => false])->save();
        self::assertTrue($actor->hasPermission(LegalWorkflowPermissions::APPROVE, ['organization_id' => $fixture->organization->id]));
        foreach ($cases as [$document]) {
            if ($document->accessGrants()->where('subject_kind', 'internal_role')->exists()) {
                self::assertFalse($authorization->can($actor, $document, LegalWorkflowPermissions::APPROVE));
            }
        }
        $permissionAssignment = UserRoleAssignment::query()->where('user_id', $actor->id)->where('is_active', true)->sole();
        $permissionAssignment->forceFill(['is_active' => false])->save();
        $allowedDocument = $cases[0][0];
        self::assertFalse($authorization->can($actor, $allowedDocument, LegalWorkflowPermissions::APPROVE));
        self::assertFalse($authorization->forMany($actor, collect([$allowedDocument]), [LegalWorkflowPermissions::APPROVE])[$allowedDocument->id][LegalWorkflowPermissions::APPROVE]);
        $permissionAssignment->forceFill(['is_active' => true])->save();
        $permissionRole = OrganizationCustomRole::query()->where('organization_id', $fixture->organization->id)->where('slug', $permissionAssignment->role_slug)->sole();
        $permissionRole->forceFill(['system_permissions' => []])->save();
        self::assertFalse($authorization->can($actor, $allowedDocument, LegalWorkflowPermissions::APPROVE));
        self::assertFalse($authorization->forMany($actor, collect([$allowedDocument]), [LegalWorkflowPermissions::APPROVE])[$allowedDocument->id][LegalWorkflowPermissions::APPROVE]);
        $permissionRole->forceFill(['system_permissions' => self::PERMISSIONS])->save();
        self::assertTrue($authorization->can($actor, $allowedDocument, LegalWorkflowPermissions::APPROVE));
        $fixture->organization->users()->updateExistingPivot($actor->id, ['is_active' => false]);
        $matrix = $authorization->forMany($actor, $documents, self::PERMISSIONS);
        foreach ($documents as $document) {
            self::assertFalse($authorization->can($actor, $document, LegalWorkflowPermissions::VIEW));
            self::assertNotContains(true, $matrix[$document->id]);
        }
    }

    public function test_actual_internal_boundary_is_batched_for_one_hundred_documents(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember([], self::PERMISSIONS);
        $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
        AuthorizationContext::getProjectContext($project->id, $fixture->organization->id);
        $project->users()->attach($actor->id, ['is_active' => true]);
        $documents = collect();
        foreach (range(1, 100) as $index) {
            $document = $this->document($fixture, $project, $index % 2 === 0 ? 'secret' : 'restricted');
            if ($index % 3 === 0) {
                $this->grant($fixture, $document, $actor);
            }
            $documents->push($document);
        }
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            app(LegalWorkflowAuthorization::class)->forMany($actor, collect([$documents->get(2)]), self::PERMISSIONS);
            $singleQueryCount = count($connection->getQueryLog());
            $connection->flushQueryLog();
            $matrix = app(LegalWorkflowAuthorization::class)->forMany($actor, $documents, self::PERMISSIONS);
            $queries = $connection->getQueryLog();
            $queryCount = count($queries);
        } finally {
            $connection->disableQueryLog();
        }
        self::assertLessThanOrEqual(18, $queryCount, json_encode(array_column($queries, 'query'), JSON_THROW_ON_ERROR));
        self::assertLessThanOrEqual($singleQueryCount + 1, $queryCount);
        foreach ($documents->values() as $index => $document) {
            foreach (self::PERMISSIONS as $permission) {
                self::assertSame(($index + 1) % 3 === 0, $matrix[$document->id][$permission]);
            }
        }
    }

    public function test_external_grant_semantics_and_batch_parity_are_preserved(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $actor = $fixture->addMember();
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'title' => 'External workflow',
            'document_type' => 'contract', 'status' => 'draft', 'confidentiality_level' => 'secret',
        ]);
        $grant = LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->foreignOrganization->id, 'document_id' => $document->id,
            'subject_kind' => 'external_user', 'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $actor->id, 'abilities' => ['view', 'approve'],
            'granted_by_user_id' => $fixture->foreignOwner->id,
        ]);
        $authorization = app(LegalWorkflowAuthorization::class);
        $matrix = $authorization->forMany($actor, collect([$document]), self::PERMISSIONS);
        foreach (self::PERMISSIONS as $permission) {
            $allowed = in_array($permission, [LegalWorkflowPermissions::VIEW, LegalWorkflowPermissions::APPROVE, LegalWorkflowPermissions::REJECT, LegalWorkflowPermissions::RETURN], true);
            self::assertSame($allowed, $authorization->can($actor, $document, $permission));
            self::assertSame($allowed, $matrix[$document->id][$permission]);
        }
        $grant->forceFill([
            'revoked_at' => now(), 'revoked_by_user_id' => $fixture->foreignOwner->id,
            'revocation_reason' => 'External access revoked',
        ])->save();
        $matrix = $authorization->forMany($actor, collect([$document]), self::PERMISSIONS);
        foreach (self::PERMISSIONS as $permission) {
            self::assertFalse($authorization->can($actor, $document, $permission));
            self::assertFalse($matrix[$document->id][$permission]);
        }
    }

    private function document(AssistantRealAuthorizationFixture $fixture, Project $project, string $level): LegalArchiveDocument
    {
        return LegalArchiveDocument::query()->create([
            'organization_id' => $fixture->organization->id, 'primary_project_id' => $project->id,
            'title' => 'Workflow access matrix', 'document_type' => 'contract', 'status' => 'draft',
            'confidentiality_level' => $level, 'lock_version' => 0,
        ]);
    }

    private function grant(AssistantRealAuthorizationFixture $fixture, LegalArchiveDocument $document, User $actor, array $overrides = []): LegalDocumentAccessGrant
    {
        $grant = new LegalDocumentAccessGrant;
        $grant->forceFill(array_replace([
            'organization_id' => $fixture->organization->id, 'document_id' => $document->id,
            'subject_kind' => 'internal_user', 'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $actor->id, 'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ], $overrides))->save();

        return $grant;
    }
}
