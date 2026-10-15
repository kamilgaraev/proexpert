<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\ContractOrganizationView;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SupplementaryAgreement;
use App\Modules\Core\AccessController;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Contract\ProjectSupplementaryAgreementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\Support\DesignManagementTestEntitlements;
use Tests\TestCase;

final class AgreementProjectScopeSecurityTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    public static function accessModes(): array
    {
        return [['assigned_projects'], ['all_projects']];
    }

    #[DataProvider('accessModes')]
    public function test_project_role_cannot_read_agreements_in_another_accessible_project(string $mode): void
    {
        $actor = $this->context($mode);
        [$first, $firstContract, $firstAgreement] = $this->fixture($actor);
        [$second, $secondContract, $secondAgreement] = $this->fixture($actor);
        $this->grant($actor, ['agreements.view'], $first);

        self::assertTrue(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor->user, $second));
        self::assertTrue($this->can($actor, $first, 'agreements.view'));
        self::assertFalse($this->can($actor, $second, 'agreements.view'));
        $headers = $actor->authHeaders();
        $this->getJson('/api/v1/admin/projects/'.$first->id.'/agreements/'.$firstAgreement->id, $headers)->assertOk();
        $this->getJson('/api/v1/admin/projects/'.$second->id.'/agreements/'.$secondAgreement->id, $headers)->assertForbidden();
        $this->getJson('/api/v1/admin/projects/'.$second->id.'/agreements/'.$secondAgreement->id.'?project_id='.$first->id, $headers)->assertForbidden();
        $this->getJson('/api/v1/admin/projects/'.$second->id.'/agreements?contract_id='.$secondContract->id, $headers)->assertForbidden();
        self::assertSame((int) $firstContract->id, (int) $firstAgreement->fresh()->contract_id);
    }

    #[DataProvider('accessModes')]
    public function test_project_role_cannot_mutate_another_accessible_project(string $mode): void
    {
        $actor = $this->context($mode);
        [$first] = $this->fixture($actor);
        [$second, $contract, $agreement] = $this->fixture($actor);
        $this->grant($actor, ['agreements.create', 'agreements.edit', 'agreements.delete'], $first);
        $beforeAgreement = $agreement->fresh()->getRawOriginal();
        $beforeContract = $contract->fresh()->getRawOriginal();
        $beforeCount = SupplementaryAgreement::query()->count();
        $base = '/api/v1/admin/projects/'.$second->id.'/agreements';
        $headers = $actor->authHeaders();

        $this->postJson($base, $this->payload($contract, 'DENIED'), $headers)->assertForbidden();
        $this->putJson($base.'/'.$agreement->id, $this->payload($contract, 'DENIED-UPDATE'), $headers)->assertForbidden();
        $this->deleteJson($base.'/'.$agreement->id, [], $headers)->assertForbidden();
        $this->postJson($base.'/'.$agreement->id.'/apply-changes', [], $headers)->assertForbidden();
        self::assertSame($beforeCount, SupplementaryAgreement::query()->count());
        self::assertSame($beforeAgreement, $agreement->fresh()->getRawOriginal());
        self::assertSame($beforeContract, $contract->fresh()->getRawOriginal());
    }

    public function test_each_project_operation_works_without_a_separate_view_grant(): void
    {
        $this->enableImmutableAuditWriter();
        $actor = $this->context();
        [$project, $contract] = $this->fixture($actor);
        $this->grant($actor, ['agreements.create', 'agreements.edit', 'agreements.delete'], $project);
        self::assertFalse($this->can($actor, $project, 'agreements.view'));
        $base = '/api/v1/admin/projects/'.$project->id.'/agreements';
        $headers = $actor->authHeaders();
        $this->postJson($base, $this->payload($contract, 'NEW'), $headers)->assertCreated();
        $agreement = SupplementaryAgreement::query()->where('contract_id', $contract->id)->where('number', 'NEW')->firstOrFail();
        $this->putJson($base.'/'.$agreement->id, $this->payload($contract, 'UPDATED'), $headers)->assertOk();
        self::assertSame('UPDATED', $agreement->fresh()->number);
        $this->postJson($base.'/'.$agreement->id.'/apply-changes', [], $headers)->assertOk();
        $amount = $contract->fresh()->getRawOriginal('total_amount');
        self::assertNotNull($agreement->fresh()->applied_at);
        $this->postJson($base.'/'.$agreement->id.'/apply-changes', [], $headers)->assertOk();
        self::assertSame($amount, $contract->fresh()->getRawOriginal('total_amount'));
        $this->deleteJson($base.'/'.$agreement->id, [], $headers)->assertConflict();
        $this->postJson($base, $this->payload($contract, 'TO-DELETE'), $headers)->assertCreated();
        $mutable = SupplementaryAgreement::query()->where('contract_id', $contract->id)->where('number', 'TO-DELETE')->firstOrFail();
        $this->deleteJson($base.'/'.$mutable->id, [], $headers)->assertOk();
        $this->assertSoftDeleted($mutable);
    }

    #[DataProvider('accessModes')]
    public function test_project_authorization_precedes_mutation_validation(string $mode): void
    {
        $actor = $this->context($mode);
        [$first] = $this->fixture($actor);
        [$second, $contract, $agreement] = $this->fixture($actor);
        $this->grant($actor, ['agreements.create', 'agreements.edit'], $first);
        $base = '/api/v1/admin/projects/'.$second->id.'/agreements';
        foreach ([[], ['contract_id' => 999999999], $this->payload($contract, $agreement->number) + ['supersede_agreement_ids' => [999999999]]] as $payload) {
            $this->postJson($base, $payload, $actor->authHeaders())->assertForbidden();
            $this->putJson($base.'/'.$agreement->id, array_replace($payload, ['agreement_date' => 'invalid']), $actor->authHeaders())->assertForbidden();
        }
    }

    public function test_validation_does_not_distinguish_foreign_and_missing_references(): void
    {
        $actor = $this->context();
        [$project, $contract, $agreement] = $this->fixture($actor);
        [, $otherContract, $otherAgreement] = $this->fixture($actor);
        $this->grant($actor, ['agreements.create', 'agreements.edit'], $project);
        $base = '/api/v1/admin/projects/'.$project->id.'/agreements';
        $headers = $actor->authHeaders();
        $foreign = $this->postJson($base, $this->payload($otherContract, 'FOREIGN'), $headers)->assertNotFound();
        $missingContract = $this->payload($contract, 'MISSING-CONTRACT');
        $missingContract['contract_id'] = 999999999;
        $this->postJson($base, $missingContract, $headers)->assertNotFound()->assertJsonPath('message', $foreign->json('message'));
        $this->putJson($base.'/'.$otherAgreement->id, [], $headers)->assertNotFound();

        foreach (['POST', 'PUT'] as $method) {
            $url = $method === 'POST' ? $base : $base.'/'.$agreement->id;
            $errors = [];
            foreach ([$otherAgreement->id, 999999999] as $supersededId) {
                $payload = $this->payload($contract, 'VALIDATION-'.$method);
                $payload['supersede_agreement_ids'] = [$supersededId];
                $response = $this->json($method, $url, $payload, $headers)->assertUnprocessable();
                $response->assertJsonValidationErrors('supersede_agreement_ids.0');
                $errors[] = $response->json('errors');
            }
            self::assertSame($errors[0], $errors[1]);
        }
        self::assertSame('SEC-DS-'.$project->id, $agreement->fresh()->number);
    }

    public function test_organization_grant_allows_first_project_context_and_filters_stay_scoped(): void
    {
        $actor = $this->context();
        [$project, $contract, $agreement] = $this->fixture($actor);
        [, $otherContract] = $this->fixture($actor);
        AuthorizationContext::query()->where('type', AuthorizationContext::TYPE_PROJECT)
            ->where('resource_id', $project->id)->delete();
        $this->grant($actor, ['agreements.view']);
        $found = app(ProjectSupplementaryAgreementService::class)->find($actor->user, $actor->organization->id, $project->id, $agreement->id);
        self::assertSame($agreement->id, $found->id);
        $base = '/api/v1/admin/projects/'.$project->id.'/agreements';
        $this->getJson($base.'?contract_id='.$contract->id, $actor->authHeaders())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?contract_id='.$otherContract->id, $actor->authHeaders())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_participant_and_multi_project_contract_access_is_preserved_and_foreign_contract_is_denied(): void
    {
        $actor = $this->context();
        $participantProject = Project::factory()->create();
        $participantProject->organizations()->attach($actor->organization->id, [
            'role' => 'contractor', 'role_new' => 'contractor', 'is_active' => true,
            'invited_at' => now(), 'accepted_at' => now(),
        ]);
        [$project, $contract, $agreement] = $this->fixture($actor, $participantProject);
        [$second] = $this->fixture($actor);
        $contract->update(['is_multi_project' => true]);
        $contract->projects()->attach([$project->id, $second->id]);
        $this->grant($actor, ['agreements.view']);
        $headers = $actor->authHeaders();
        foreach ([$project, $second] as $accessibleProject) {
            $this->getJson('/api/v1/admin/projects/'.$accessibleProject->id.'/agreements/'.$agreement->id, $headers)->assertOk();
        }
        $foreignActor = $this->context();
        [, , $foreignAgreement] = $this->fixture($foreignActor);
        $this->getJson('/api/v1/admin/projects/'.$second->id.'/agreements/'.$foreignAgreement->id, $headers)->assertNotFound();
        $project->organizations()->updateExistingPivot($actor->organization->id, ['is_active' => false]);
        $this->assertServiceDenied($actor, $project, $agreement);
    }

    public static function revocations(): array
    {
        return [['role'], ['assignment'], ['membership'], ['organization']];
    }

    public function test_list_and_detail_share_contract_view_and_legacy_boundaries_before_pagination(): void
    {
        $actor = $this->context();
        [$project, $ownerContract, $ownerAgreement] = $this->fixture($actor);
        DB::table('contracts')->where('id', $ownerContract->id)->update(['is_self_execution' => true]);
        $this->grant($actor, ['agreements.view'], $project);
        $owner = Organization::factory()->create();
        $contractor = Contractor::query()->create([
            'organization_id' => $owner->id, 'source_organization_id' => $actor->organization->id,
            'name' => $actor->organization->name, 'contractor_type' => 'invited_organization',
        ]);
        $allowedIds = [$ownerAgreement->id];
        foreach ([['legacy', false, null, true], ['self', true, null, false], ['active', true, false, true], ['revoked', false, true, false]] as [$name, $selfExecution, $revoked, $allowed]) {
            $contract = Contract::query()->create([
                'organization_id' => $owner->id, 'project_id' => $project->id,
                'contractor_id' => $name === 'active' ? null : $contractor->id,
                'number' => 'VIEW-'.$name, 'date' => '2026-10-15', 'subject' => 'View regression',
                'base_amount' => 1000, 'total_amount' => 1000, 'status' => 'active',
                'is_fixed_amount' => true, 'is_multi_project' => false, 'is_self_execution' => $selfExecution,
            ]);
            if ($revoked !== null) {
                ContractOrganizationView::query()->create([
                    'contract_id' => $contract->id, 'organization_id' => $actor->organization->id,
                    'access_revoked_at' => $revoked ? now() : null,
                ]);
            }
            $agreement = SupplementaryAgreement::query()->create([
                'contract_id' => $contract->id, 'number' => 'VIEW-'.$name,
                'agreement_date' => '2026-10-15', 'change_amount' => 100, 'subject_changes' => ['View regression'],
            ]);
            $this->getJson('/api/v1/admin/projects/'.$project->id.'/agreements/'.$agreement->id, $actor->authHeaders())
                ->assertStatus($allowed ? 200 : 404);
            if ($allowed) {
                $allowedIds[] = $agreement->id;
            }
        }
        $page = app(ProjectSupplementaryAgreementService::class)->paginate($actor->user, $actor->organization->id,
            $project->id, 2, [], 'agreement_date', 'desc');
        self::assertSame(count($allowedIds), $page->total());
        self::assertCount(2, $page->items());
        foreach ($page->items() as $agreement) {
            self::assertContains($agreement->id, $allowedIds);
        }
        $response = $this->getJson('/api/v1/admin/projects/'.$project->id.'/agreements?per_page=10', $actor->authHeaders())->assertOk();
        self::assertEqualsCanonicalizing($allowedIds, array_column($response->json('data'), 'id'));
    }

    #[DataProvider('revocations')]
    public function test_service_uses_current_access_after_a_cached_allow(string $revocation): void
    {
        $actor = $this->context();
        [$project, , $agreement] = $this->fixture($actor);
        $assignment = $this->grant($actor, ['agreements.view'], $project);
        $headers = $actor->authHeaders();
        $url = '/api/v1/admin/projects/'.$project->id.'/agreements/'.$agreement->id;
        $this->getJson($url, $headers)->assertOk();
        self::assertTrue(app(AuthorizationService::class)->can($actor->user, 'agreements.view', [
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
        ]));
        match ($revocation) {
            'role' => DB::table($assignment->getTable())->where('id', $assignment->id)->update(['is_active' => false]),
            'assignment' => DB::table('project_user')->where('project_id', $project->id)->where('user_id', $actor->user->id)->update(['is_active' => false]),
            'membership' => DB::table('organization_user')->where('organization_id', $actor->organization->id)->where('user_id', $actor->user->id)->update(['is_active' => false]),
            'organization' => DB::table('organizations')->where('id', $actor->organization->id)->update(['is_active' => false]),
        };
        $this->assertServiceDenied($actor, $project, $agreement);
        if (in_array($revocation, ['role', 'assignment'], true)) {
            $this->getJson($url, $headers)->assertForbidden();
        }
    }

    private function assertServiceDenied(AdminApiTestContext $actor, Project $project, SupplementaryAgreement $agreement): void
    {
        try {
            app(ProjectSupplementaryAgreementService::class)->find($actor->user, $actor->organization->id, $project->id, $agreement->id);
            self::fail('Current project access must be enforced.');
        } catch (AuthorizationException) {
            self::assertNotNull($agreement->fresh());
        }
    }

    private function payload(Contract $contract, string $number): array
    {
        return [
            'contract_id' => $contract->id, 'number' => $number, 'agreement_date' => '2026-10-15',
            'change_amount' => 100, 'subject_changes' => ['Scope regression'],
        ];
    }

    private function context(string $mode = 'assigned_projects'): AdminApiTestContext
    {
        $actor = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, [
            'is_owner' => false, 'project_access_mode' => $mode,
        ]);
        DesignManagementTestEntitlements::activate($actor);
        self::assertTrue(app(AccessController::class)->hasModuleAccess($actor->organization->id, 'contract-management'));
        self::assertTrue(app(OrganizationEntitlementService::class)->getEffectiveModules($actor->organization->id)->contains('slug', 'contract-management'));

        return $actor;
    }

    private function fixture(AdminApiTestContext $actor, ?Project $project = null): array
    {
        $project ??= Project::factory()->create(['organization_id' => $actor->organization->id]);
        $project->users()->attach($actor->user->id, ['role' => 'member', 'is_active' => true]);
        AuthorizationContext::getProjectContext($project->id, $actor->organization->id);
        $contract = Contract::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'number' => 'SEC-AGREEMENT-'.$project->id, 'date' => '2026-10-15',
            'subject' => 'Scope regression', 'base_amount' => 1000, 'total_amount' => 1000,
            'status' => 'active', 'is_fixed_amount' => true, 'is_multi_project' => false,
        ]);
        $agreement = SupplementaryAgreement::query()->create([
            'contract_id' => $contract->id, 'number' => 'SEC-DS-'.$project->id,
            'agreement_date' => '2026-10-15', 'change_amount' => 100,
            'subject_changes' => ['Scope regression'],
        ]);

        return [$project, $contract, $agreement];
    }

    private function grant(AdminApiTestContext $actor, array $permissions, ?Project $project = null): UserRoleAssignment
    {
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Agreement operator '.OrganizationCustomRole::query()->count(),
            'created_by' => $actor->user->id, 'system_permissions' => ['admin.access'],
            'module_permissions' => ['contract-management' => $permissions],
            'interface_access' => ['admin'], 'is_active' => true,
        ]);

        return UserRoleAssignment::assignRole($actor->user, $role->slug,
            $project ? AuthorizationContext::getProjectContext($project->id, $actor->organization->id)
                : AuthorizationContext::getOrganizationContext($actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);
    }

    private function can(AdminApiTestContext $actor, Project $project, string $permission): bool
    {
        return app(AuthorizationService::class)->canCurrent($actor->user, $permission, [
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'strict_project_scope' => true,
        ]);
    }
}
