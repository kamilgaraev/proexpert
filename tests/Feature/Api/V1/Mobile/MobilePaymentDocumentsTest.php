<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobilePaymentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_reuses_the_same_key_only_for_the_same_payload(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $payee = Organization::factory()->verified()->create();
        $headers = $context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile-payment-create-0001'];
        $payload = [
            'document_type' => 'payment_request',
            'document_date' => '2026-09-23',
            'project_id' => $project->id,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $payee->id,
            'amount' => 100,
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents', $payload);
        $first->assertCreated();
        $id = $first->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents', array_replace($payload, ['amount' => 200]))
            ->assertStatus(409);
        $this->assertSame(1, PaymentDocument::query()->where('origin_key', 'mobile:'.$context->user->id.':mobile-payment-create-0001')->count());
    }

    public function test_list_uses_server_pagination_and_organization_isolation(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $visible = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-LOCAL-1');
        $this->createDocument((int) $context->organization->id, null, 'MOBILE-PROJECTLESS-1');

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->createDocument((int) $foreignOrganization->id, (int) $foreignProject->id, 'MOBILE-FOREIGN-1');

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/payments/documents?project_id='.$project->id.'&page=1&per_page=1');

        $response->assertOk()
            ->assertJsonPath('data.items.0.id', $visible->id)
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.total', 1);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/payments/documents')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_project_grant_scopes_list_and_record_access_without_exposing_projectless_documents(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $visibleProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $hiddenProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $visible = $this->createDocument((int) $context->organization->id, (int) $visibleProject->id, 'MOBILE-SCOPED-1');
        $hidden = $this->createDocument((int) $context->organization->id, (int) $hiddenProject->id, 'MOBILE-SCOPED-2');
        $this->createDocument((int) $context->organization->id, null, 'MOBILE-SCOPED-3');

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

        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $visible->id);
        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents/'.$hidden->id)
            ->assertNotFound();
    }

    public function test_assigned_payment_approval_is_available_and_decided_in_mobile(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-APPROVAL-1');
        $document->forceFill(['status' => PaymentDocumentStatus::PENDING_APPROVAL])->save();
        PaymentApproval::query()->create([
            'organization_id' => $context->organization->id,
            'payment_document_id' => $document->id,
            'approver_user_id' => $context->user->id,
            'approval_permission' => 'payments.transaction.approve',
            'status' => 'pending',
        ]);
        $headers = $context->mobileAuthHeaders();

        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('data.can_approve', true);
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$document->id.'/approve', [
            'comment' => 'Проверено на объекте',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('approved', $document->fresh()->status->value);

        $generic = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-APPROVAL-2');
        $generic->forceFill(['status' => PaymentDocumentStatus::PENDING_APPROVAL])->save();
        PaymentApproval::query()->create([
            'organization_id' => $context->organization->id,
            'payment_document_id' => $generic->id,
            'approval_permission' => 'payments.transaction.approve',
            'status' => 'pending',
        ]);
        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents/'.$generic->id)
            ->assertOk()
            ->assertJsonPath('data.can_approve', true)
            ->assertJsonPath('data.can_reject', true);
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$generic->id.'/reject', [
            'reason' => 'Неверная сумма',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    private function createDocument(int $organizationId, ?int $projectId, string $number): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => $number,
            'document_date' => '2026-06-02',
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::OTHER,
            'amount' => 5000,
            'paid_amount' => 0,
            'remaining_amount' => 5000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
        ]);
    }

    private function activatePaymentsModule(int $organizationId): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => 'payments'],
            [
                'name' => 'Payments',
                'version' => '1.0.0',
                'type' => 'core',
                'billing_model' => 'free',
                'category' => 'finance',
                'permissions' => array_keys(app(\App\BusinessModules\Core\Payments\PaymentsModule::class)->getPermissions()),
                'is_active' => true,
                'is_system_module' => false,
            ]
        );
        $module->forceFill([
            'permissions' => array_keys(app(\App\BusinessModules\Core\Payments\PaymentsModule::class)->getPermissions()),
            'is_active' => true,
        ])->save();

        OrganizationModuleActivation::query()->create([
            'organization_id' => $organizationId,
            'module_id' => $module->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }
}
