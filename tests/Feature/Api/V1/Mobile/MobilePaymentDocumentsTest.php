<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentApproval;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Contractor;
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
        $payee = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Поставщик для мобильного платежа',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $headers = $context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile-payment-create-0001'];
        $payload = [
            'document_type' => 'payment_request',
            'document_date' => '2026-09-23',
            'project_id' => $project->id,
            'payer_organization_id' => $context->organization->id,
            'payee_contractor_id' => $payee->id,
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

    public function test_create_returns_validation_error_for_form_payload_without_transaction_parties(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $key = 'mobile-payment-form-payload-001';
        $payload = [
            'document_type' => 'payment_request',
            'document_date' => '2026-09-28',
            'project_id' => $project->id,
            'amount' => 100,
            'payment_purpose' => 'Оплата по заявке',
            'description' => 'Создано из мобильной формы',
        ];

        $response = $this->withHeaders($context->mobileAuthHeaders() + ['Idempotency-Key' => $key])
            ->postJson('/api/v1/mobile/payments/documents', $payload);

        $response->assertStatus(422)->assertJsonPath('message', trans_message('payments.validation_error'));
        $this->assertSame(0, PaymentDocument::query()
            ->where('organization_id', $context->organization->id)
            ->where('origin_key', 'mobile:'.$context->user->id.':'.$key)
            ->count());
    }

    public function test_options_returns_only_own_contractors_with_pagination_and_search(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $first = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Scoped Supplier Alpha',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $second = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Scoped Supplier Beta',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreign = Contractor::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Scoped Supplier Foreign',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        $firstPage = $this->withHeaders($context->mobileAuthHeaders())->getJson(
            '/api/v1/mobile/payments/documents/options?'.http_build_query([
                'project_id' => $project->id,
                'search' => 'Scoped Supplier',
                'page' => 1,
                'per_page' => 1,
            ]),
        );
        $firstPage->assertOk()
            ->assertJsonPath('data.current_organization.id', $context->organization->id)
            ->assertJsonPath('data.contractors.items.0.id', $first->id)
            ->assertJsonPath('data.contractors.meta.current_page', 1)
            ->assertJsonPath('data.contractors.meta.per_page', 1)
            ->assertJsonPath('data.contractors.meta.total', 2)
            ->assertJsonPath('data.contractors.meta.last_page', 2);
        $firstPageContractorIds = array_column($firstPage->json('data.contractors.items'), 'id');
        self::assertSame([(int) $first->id], $firstPageContractorIds);
        self::assertNotContains((int) $foreign->id, $firstPageContractorIds);

        $this->withHeaders($context->mobileAuthHeaders())->getJson(
            '/api/v1/mobile/payments/documents/options?'.http_build_query([
                'project_id' => $project->id,
                'search' => 'Scoped Supplier',
                'page' => 2,
                'per_page' => 1,
            ]),
        )->assertOk()
            ->assertJsonPath('data.contractors.items.0.id', $second->id)
            ->assertJsonPath('data.contractors.meta.current_page', 2);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/payments/documents/options?per_page=51')
            ->assertStatus(422);
    }

    public function test_options_allows_edit_only_roles_and_denies_missing_permissions_or_unavailable_projects(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        UserRoleAssignment::query()->where('user_id', $context->user->id)->delete();
        $editOnlyRole = OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Mobile payment editor',
            'slug' => 'mobile_payment_editor_'.$context->organization->id,
            'system_permissions' => [],
            'module_permissions' => ['payments' => ['payments.invoice.edit']],
            'interface_access' => ['mobile'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $context->user->id,
        ]);
        UserRoleAssignment::assignRole(
            user: $context->user,
            roleSlug: $editOnlyRole->slug,
            context: AuthorizationContext::getOrganizationContext((int) $context->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
        $authorization = app(\App\Domain\Authorization\Services\AuthorizationService::class);
        $this->assertTrue($authorization->can($context->user, 'payments.invoice.edit', [
            'organization_id' => (int) $context->organization->id,
        ]));
        $this->assertFalse($authorization->can($context->user, 'payments.invoice.create', [
            'organization_id' => (int) $context->organization->id,
        ]));
        $headers = $context->mobileAuthHeaders();

        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents/options?project_id='.$project->id)
            ->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents/options?project_id='.$foreignProject->id)
            ->assertNotFound();

        $deniedContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $deniedContext->organization->id);
        UserRoleAssignment::query()->where('user_id', $deniedContext->user->id)->delete();
        $noPaymentAccessRole = OrganizationCustomRole::query()->create([
            'organization_id' => $deniedContext->organization->id,
            'name' => 'Mobile access without payment rights',
            'slug' => 'mobile_no_payment_rights_'.$deniedContext->organization->id,
            'system_permissions' => [],
            'module_permissions' => [],
            'interface_access' => ['mobile'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $deniedContext->user->id,
        ]);
        UserRoleAssignment::assignRole(
            user: $deniedContext->user,
            roleSlug: $noPaymentAccessRole->slug,
            context: AuthorizationContext::getOrganizationContext((int) $deniedContext->organization->id),
            roleType: UserRoleAssignment::TYPE_CUSTOM,
        );
        $this->assertFalse($authorization->can($deniedContext->user, 'payments.invoice.create', [
            'organization_id' => (int) $deniedContext->organization->id,
        ]));
        $this->assertFalse($authorization->can($deniedContext->user, 'payments.invoice.edit', [
            'organization_id' => (int) $deniedContext->organization->id,
        ]));
        $this->withHeaders($deniedContext->mobileAuthHeaders())->getJson('/api/v1/mobile/payments/documents/options')
            ->assertForbidden();
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
        $hidden->forceFill([
            'status' => PaymentDocumentStatus::DRAFT,
            'amount' => 5000,
            'remaining_amount' => 5000,
        ])->save();
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

        $this->withHeaders($headers)->putJson('/api/v1/mobile/payments/documents/'.$hidden->id, [
            'amount' => 9000,
        ])->assertNotFound();
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$hidden->id.'/submit')
            ->assertNotFound();
        $this->assertSame('draft', $hidden->fresh()->status->value);
        $this->assertSame('5000.00', (string) $hidden->fresh()->amount);
    }

    public function test_mobile_draft_can_be_updated_and_submitted_with_persisted_state(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $headers = $context->mobileAuthHeaders();
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Поставщик для отправки',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $options = $this->withHeaders($headers)->getJson(
            '/api/v1/mobile/payments/documents/options?project_id='.$project->id,
        );
        $options->assertOk()->assertJsonPath('data.contractors.items.0.id', $contractor->id);
        $currentOrganizationId = $options->json('data.current_organization.id');
        $created = $this->withHeaders($headers + ['Idempotency-Key' => 'mobile-payment-submit-flow-001'])
            ->postJson('/api/v1/mobile/payments/documents', [
                'document_type' => 'payment_order',
                'direction' => 'outgoing',
                'document_date' => '2026-09-28',
                'project_id' => $project->id,
                'payer_organization_id' => $currentOrganizationId,
                'payee_contractor_id' => $contractor->id,
                'amount' => 5000,
                'payment_purpose' => 'Оплата поставщику',
                'bank_account' => '40702810900000000001',
                'bank_bik' => '044525225',
            ])->assertCreated()->assertJsonPath('data.status', PaymentDocumentStatus::DRAFT->value);
        $documentId = (int) $created->json('data.id');

        $this->withHeaders($headers)->getJson('/api/v1/mobile/payments/documents/'.$documentId)
            ->assertOk()
            ->assertJsonPath('data.payer_name', $context->organization->name)
            ->assertJsonPath('data.payee_name', $contractor->name);

        $this->withHeaders($headers)->putJson('/api/v1/mobile/payments/documents/'.$documentId, [
            'amount' => 6500,
            'description' => 'Изменено в мобильном приложении',
        ])->assertOk();

        $document = PaymentDocument::query()->findOrFail($documentId);
        $this->assertSame('6500.00', (string) $document->amount);
        $this->assertSame('Изменено в мобильном приложении', $document->description);
        $this->assertSame((int) $currentOrganizationId, (int) $document->payer_organization_id);
        $this->assertSame((int) $contractor->id, (int) $document->payee_contractor_id);

        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$documentId.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', PaymentDocumentStatus::PENDING_APPROVAL->value);

        $this->assertSame(PaymentDocumentStatus::PENDING_APPROVAL, $document->fresh()->status);
        $this->assertNotNull($document->fresh()->submitted_at);
    }

    public function test_mobile_submit_reports_missing_party_and_bank_details_as_validation_error(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-SUBMIT-INVALID-1');
        $document->forceFill(['status' => PaymentDocumentStatus::DRAFT])->save();

        $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/payments/documents/'.$document->id.'/submit')
            ->assertStatus(422);

        $this->assertSame(PaymentDocumentStatus::DRAFT, $document->fresh()->status);
        $this->assertNull($document->fresh()->submitted_at);
    }

    public function test_partial_mobile_edit_preserves_an_existing_external_organization_party(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $externalOrganization = Organization::factory()->verified()->create();
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-PARTY-PRESERVE-1');
        $document->forceFill([
            'status' => PaymentDocumentStatus::DRAFT,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $externalOrganization->id,
            'payment_purpose' => 'Оплата по договору',
        ])->save();

        $this->withHeaders($context->mobileAuthHeaders())
            ->putJson('/api/v1/mobile/payments/documents/'.$document->id, ['amount' => 5100])
            ->assertOk()
            ->assertJsonPath('data.payee_name', $externalOrganization->name);

        $this->assertSame((int) $externalOrganization->id, (int) $document->fresh()->payee_organization_id);
    }

    public function test_mobile_update_can_switch_payee_organization_to_selected_contractor(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $externalOrganization = Organization::factory()->verified()->create();
        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Выбранный подрядчик при редактировании',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-PARTY-SWITCH-1');
        $document->forceFill([
            'status' => PaymentDocumentStatus::DRAFT,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $externalOrganization->id,
            'payee_contractor_id' => null,
            'payment_purpose' => 'Оплата по договору',
        ])->save();

        $this->withHeaders($context->mobileAuthHeaders())
            ->putJson('/api/v1/mobile/payments/documents/'.$document->id, [
                'payee_organization_id' => null,
                'payee_contractor_id' => $contractor->id,
            ])->assertOk()
            ->assertJsonPath('data.payee_name', $contractor->name);

        $this->assertNull($document->fresh()->payee_organization_id);
        $this->assertSame((int) $contractor->id, (int) $document->fresh()->payee_contractor_id);
        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/payments/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('data.payee_name', $contractor->name);
    }

    public function test_mobile_update_rejects_contractor_from_another_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignContractor = Contractor::query()->create([
            'organization_id' => $foreignOrganization->id,
            'name' => 'Чужой подрядчик',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-PARTY-TENANT-1');
        $document->forceFill([
            'status' => PaymentDocumentStatus::DRAFT,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $context->organization->id,
            'payment_purpose' => 'Оплата по договору',
        ])->save();

        $this->withHeaders($context->mobileAuthHeaders())
            ->putJson('/api/v1/mobile/payments/documents/'.$document->id, [
                'payee_organization_id' => null,
                'payee_contractor_id' => $foreignContractor->id,
            ])->assertStatus(422)
            ->assertJsonPath('message', trans_message('payments.validation_error'));

        $this->assertSame((int) $context->organization->id, (int) $document->fresh()->payee_organization_id);
        $this->assertNull($document->fresh()->payee_contractor_id);
    }

    public function test_mobile_update_maps_missing_payer_domain_validation_to_translated_422(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-PARTY-MISSING-1');
        $document->forceFill([
            'status' => PaymentDocumentStatus::DRAFT,
            'payer_organization_id' => $context->organization->id,
            'payee_organization_id' => $context->organization->id,
            'payment_purpose' => 'Оплата по договору',
        ])->save();

        $this->withHeaders($context->mobileAuthHeaders())
            ->putJson('/api/v1/mobile/payments/documents/'.$document->id, [
                'payer_organization_id' => null,
                'payer_contractor_id' => null,
            ])->assertStatus(422)
            ->assertJsonPath('message', trans_message('payments.validation_error'));

        $this->assertSame((int) $context->organization->id, (int) $document->fresh()->payer_organization_id);
        $this->assertNull($document->fresh()->payer_contractor_id);
    }

    public function test_mobile_payment_registration_replays_and_rejects_payload_drift(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $this->activatePaymentsModule((int) $context->organization->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $document = $this->createDocument((int) $context->organization->id, (int) $project->id, 'MOBILE-PAYMENT-REPLAY-1');
        $headers = $context->mobileAuthHeaders() + ['Idempotency-Key' => 'mobile-payment-register-001'];
        $payload = [
            'amount' => 1250,
            'payment_method' => 'bank_transfer',
            'transaction_date' => '2026-09-27',
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$document->id.'/payments', $payload);
        $first->assertOk()
            ->assertJsonPath('data.status', PaymentDocumentStatus::PARTIALLY_PAID->value);
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$document->id.'/payments', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $document->id);
        $this->withHeaders($headers)->postJson('/api/v1/mobile/payments/documents/'.$document->id.'/payments', array_replace($payload, [
            'amount' => 1400,
        ]))->assertStatus(409);

        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertSame('1250.00', (string) $document->fresh()->paid_amount);
        $this->assertSame('3750.00', (string) $document->fresh()->remaining_amount);
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
