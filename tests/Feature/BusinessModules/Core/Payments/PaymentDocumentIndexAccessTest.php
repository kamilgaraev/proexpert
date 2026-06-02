<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\ContractorType;
use App\Enums\ProjectOrganizationRole;
use App\Enums\UserProjectAccessMode;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentDocumentIndexAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_filter_documents_by_accessible_owner_contract_and_project(): void
    {
        $participantContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Shared payment project',
        ]);

        $this->activatePaymentsModule($participantContext->organization->id);
        $this->attachParticipant($project, $participantContext->organization);
        $this->allowAllProjects($participantContext);

        $contractor = Contractor::query()->create([
            'organization_id' => $ownerOrganization->id,
            'source_organization_id' => $participantContext->organization->id,
            'name' => 'Participant contractor',
            'inn' => '7700000011',
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
            'connected_at' => now(),
        ]);

        $contract = Contract::query()->create([
            'organization_id' => $ownerOrganization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'number' => 'PAY-CONTRACT-OWNER',
            'date' => '2026-06-01',
            'subject' => 'Owner contract visible to participant',
            'total_amount' => 100000,
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        $document = PaymentDocument::query()->create([
            'organization_id' => $participantContext->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'payee_contractor_id' => $contractor->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'PAY-DOC-OWNER-CONTRACT',
            'document_date' => '2026-06-02',
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::OTHER,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'amount' => 25000,
            'paid_amount' => 0,
            'remaining_amount' => 25000,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::APPROVED,
            'due_date' => '2026-06-10',
        ]);

        $response = $this->withHeaders($participantContext->authHeaders())
            ->getJson('/api/v1/admin/payments/documents?' . http_build_query([
                'contract_id' => $contract->id,
                'contractor_id' => $contractor->id,
                'project_id' => $project->id,
                'page' => 1,
                'per_page' => 1,
            ]));

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.id', $document->id);
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
                'permissions' => ['payments.invoice.view'],
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        OrganizationModuleActivation::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'module_id' => $module->id,
            ],
            [
                'status' => 'active',
                'activated_at' => now(),
                'expires_at' => null,
            ]
        );
    }

    private function attachParticipant(Project $project, Organization $organization): void
    {
        $project->organizations()->attach($organization->id, [
            'role' => ProjectOrganizationRole::CONTRACTOR->value,
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowAllProjects(AdminApiTestContext $context): void
    {
        DB::table('organization_user')
            ->where('organization_id', $context->organization->id)
            ->where('user_id', $context->user->id)
            ->update(['project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value]);
    }
}
