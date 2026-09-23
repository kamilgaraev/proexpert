<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileActFieldConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_confirmation_is_idempotent_and_does_not_change_legal_act_status(): void
    {
        Storage::fake('s3');
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $this->activateActReportingModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Field confirmation contractor',
        ]);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'MOBILE-ACT-1',
            'date' => '2026-06-01',
            'subject' => 'Field work',
            'total_amount' => 10000,
            'status' => 'active',
        ]);
        $projectlessContract = $this->createContract((int) $context->organization->id, null, (int) $contractor->id, 'MOBILE-ACT-ORG-1');
        $projectlessAct = $this->createAct((int) $projectlessContract->id, null, 'MOBILE-KS2-ORG-1');
        $act = ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'MOBILE-KS2-1',
            'act_date' => '2026-06-10',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'amount' => 10000,
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]);
        $signature = base64_encode("\x89PNG\r\n\x1a\n".str_repeat('signature', 16));
        $payload = [
            'signature_data' => $signature,
            'idempotency_key' => 'mobile-act-confirm-0001',
        ];

        $first = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson("/api/v1/mobile/acts/{$act->id}/field-confirmations", $payload);
        $first->assertCreated()
            ->assertJsonPath('data.act_id', $act->id)
            ->assertJsonPath('data.evidence_type', 'field_acceptance')
            ->assertJsonPath('data.legal_signature', false);

        $second = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson("/api/v1/mobile/acts/{$act->id}/field-confirmations", $payload);
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $detail = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/acts/{$act->id}");
        $detail->assertOk()->assertJsonPath('data.capabilities.can_field_confirm', false);
        $allActs = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/acts')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
        $this->assertContains($projectlessAct->id, array_column($allActs->json('data.items'), 'id'));
        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/acts?project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        $this->assertDatabaseCount('act_field_confirmations', 1);
        $this->assertSame(ContractPerformanceAct::STATUS_APPROVED, $act->fresh()->status);
        $this->assertDatabaseHas('files', [
            'id' => $first->json('data.file_id'),
            'fileable_id' => $act->id,
            'category' => 'field_acceptance_signature',
        ]);
        $file = File::query()->findOrFail($first->json('data.file_id'));
        $this->assertFalse((bool) ($file->additional_info['legal_signature'] ?? true));
        Storage::disk('s3')->assertExists($file->path);
    }

    public function test_project_grant_scopes_act_list_and_card(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $this->activateActReportingModule((int) $context->organization->id);
        $visibleProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $hiddenProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Scoped act contractor',
        ]);
        $visibleContract = $this->createContract($context->organization->id, $visibleProject->id, $contractor->id, 'MOBILE-SCOPED-ACT-1');
        $hiddenContract = $this->createContract($context->organization->id, $hiddenProject->id, $contractor->id, 'MOBILE-SCOPED-ACT-2');
        $projectlessContract = $this->createContract($context->organization->id, null, $contractor->id, 'MOBILE-SCOPED-ACT-3');
        $visibleAct = $this->createAct($visibleContract->id, $visibleProject->id, 'MOBILE-SCOPED-KS2-1');
        $hiddenAct = $this->createAct($hiddenContract->id, $hiddenProject->id, 'MOBILE-SCOPED-KS2-2');
        $this->createAct($projectlessContract->id, null, 'MOBILE-SCOPED-KS2-3');

        $reviewer = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($reviewer->id, [
            'is_owner' => false,
            'is_active' => true,
            'project_access_mode' => 'assigned_projects',
        ]);
        $mobileAccessRole = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Mobile interface access',
            'slug' => 'mobile_interface_access_'.$context->organization->id,
            'system_permissions' => [],
            'module_permissions' => [],
            'interface_access' => ['mobile'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $context->user->id,
        ]);
        UserRoleAssignment::assignRole(
            user: $reviewer,
            roleSlug: $mobileAccessRole->slug,
            context: AuthorizationContext::getOrganizationContext($context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM
        );
        $reviewer->assignedProjects()->attach($visibleProject->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $reviewer->assignedProjects()->attach($hiddenProject->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        UserRoleAssignment::assignRole(
            user: $reviewer,
            roleSlug: 'project_manager',
            context: AuthorizationContext::getProjectContext($visibleProject->id, $context->organization->id)
        );
        $token = app(JwtTokenIssuer::class)->issue($reviewer, [
            'guard' => 'api_mobile',
            'organization_id' => $context->organization->id,
        ]);
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->withHeaders($headers)->getJson('/api/v1/mobile/acts')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $visibleAct->id);
        $this->withHeaders($headers)->getJson('/api/v1/mobile/acts/'.$hiddenAct->id)->assertNotFound();
    }

    private function createContract(int $organizationId, ?int $projectId, int $contractorId, string $number): Contract
    {
        return Contract::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contractor_id' => $contractorId,
            'number' => $number,
            'date' => '2026-06-01',
            'subject' => 'Scoped field work',
            'total_amount' => 10000,
            'status' => 'active',
        ]);
    }

    private function createAct(int $contractId, ?int $projectId, string $number): ContractPerformanceAct
    {
        $create = static fn (): ContractPerformanceAct => ContractPerformanceAct::query()->create([
            'contract_id' => $contractId,
            'project_id' => $projectId,
            'act_document_number' => $number,
            'act_date' => '2026-06-10',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'amount' => 10000,
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        return $projectId === null
            ? ContractPerformanceAct::withoutEvents($create)
            : $create();
    }

    private function activateActReportingModule(int $organizationId): void
    {
        $permissions = app(\App\BusinessModules\Addons\ActReporting\ActReportingModule::class)->getPermissions();
        $module = Module::query()->firstOrCreate(
            ['slug' => 'act-reporting'],
            [
                'name' => 'Act reporting',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => $permissions,
                'is_active' => true,
                'is_system_module' => false,
            ]
        );
        $module->forceFill(['permissions' => $permissions, 'is_active' => true])->save();

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
