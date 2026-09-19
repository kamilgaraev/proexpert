<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\ContractorType;
use App\Enums\ProjectOrganizationRole;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class ContractCoreExperienceControllerTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EnablesImmutableAuditWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
    }

    public function test_income_subcontract_http_keeps_selected_superior_and_real_executor_identity(): void
    {
        $context = AdminApiTestContext::create();
        $first = Organization::factory()->verified()->create();
        $second = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $first->id]);
        $this->attachProjectParticipant($project, $context->organization, ProjectOrganizationRole::SUBCONTRACTOR);
        $this->attachProjectParticipant($project, $first, ProjectOrganizationRole::CONTRACTOR);
        $this->attachProjectParticipant($project, $second, ProjectOrganizationRole::CONTRACTOR);
        $self = $this->createContractor($context->organization, 'Own organization', $context->organization);
        $wrong = $this->createContractor($context->organization, 'Other organization in own directory', $first);
        $this->allowAdminAccess();
        $url = "/api/v1/admin/projects/{$project->id}/contracts";
        $payload = [
            'project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'income',
            'contractor_id' => $self->id, 'number' => 'HTTP-INCOME-1', 'date' => '2026-09-19',
            'subject' => 'Работы субподряда', 'base_amount' => 1000, 'total_amount' => 1000,
            'is_fixed_amount' => true, 'status' => 'draft', 'idempotency_key' => 'http-income-1',
        ];
        $ambiguous = $this->withHeaders($context->authHeaders())->postJson($url, $payload);
        self::assertTrue($ambiguous->isClientError(), $ambiguous->getContent());
        self::assertSame(0, Contract::where('organization_id', $context->organization->id)->count());
        $invalidIdentity = $this->withHeaders($context->authHeaders())->postJson($url, [
            ...$payload, 'superior_organization_id' => $second->id, 'contractor_id' => $wrong->id,
        ]);
        self::assertTrue($invalidIdentity->isClientError(), $invalidIdentity->getContent());
        self::assertSame(0, Contract::where('organization_id', $context->organization->id)->count());
        foreach ([$first, $second] as $index => $superior) {
            $request = [...$payload, 'number' => 'HTTP-INCOME-'.($index + 1), 'idempotency_key' => 'http-income-'.($index + 1), 'superior_organization_id' => $superior->id];
            if ($index === 1) {
                unset($request['contractor_id']);
            }
            $response = $this->withHeaders($context->authHeaders())->postJson($url, $request)->assertCreated();
            $contract = Contract::findOrFail($response->json('data.id'));
            self::assertSame($superior->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
            self::assertSame($context->organization->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
            self::assertSame($context->organization->id, $contract->contractor->source_organization_id);
            self::assertNotNull($contract->legal_archive_document_id);
            $this->withHeaders($context->authHeaders())->postJson($url, $request)->assertOk()->assertJsonPath('data.id', $contract->id);
        }
        self::assertSame(2, Contract::where('organization_id', $context->organization->id)->count());
        self::assertSame(2, $project->organizations()->wherePivot('is_active', true)->wherePivot('role_new', ProjectOrganizationRole::CONTRACTOR->value)->count());
    }

    public function test_prepared_project_requires_general_contractor_only_when_creating_income_contract(): void
    {
        $context = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id, 'contracting_scheme' => 'general_contractor',
        ]);
        $this->attachProjectParticipant($project, $context->organization, ProjectOrganizationRole::CONTRACTOR);
        $self = $this->createContractor($context->organization, 'Own executor', $context->organization);
        $input = ['project_id' => $project->id, 'contract_side_type' => 'contract', 'direction' => 'income'];
        $previewUrl = '/api/v1/admin/contracts/party-preview?'.http_build_query($input);
        $this->withHeaders($context->authHeaders())->getJson('/api/v1/admin/projects/'.$project->id)->assertOk();
        $this->withHeaders($context->authHeaders())->getJson($previewUrl)->assertOk()
            ->assertJsonPath('data.first_party', null)
            ->assertJsonPath('data.reason', trans_message('contracts.superior_missing', ['role' => 'Генподрядчик']));
        $payload = [
            ...$input, 'contractor_id' => $self->id, 'number' => 'PREPARED-INCOME', 'date' => '2026-09-19',
            'subject' => 'Работы', 'base_amount' => 1000, 'total_amount' => 1000,
            'is_fixed_amount' => true, 'status' => 'draft', 'idempotency_key' => 'prepared-income',
        ];
        $url = '/api/v1/admin/projects/'.$project->id.'/contracts';
        $rejected = $this->withHeaders($context->authHeaders())->postJson($url, $payload);
        self::assertTrue($rejected->isClientError(), $rejected->getContent());
        self::assertSame(0, Contract::where('project_id', $project->id)->count());
        $superior = Organization::factory()->verified()->create();
        app(\App\Services\Project\ProjectParticipantService::class)->attach($project, $superior->id, ProjectOrganizationRole::GENERAL_CONTRACTOR);
        $this->withHeaders($context->authHeaders())->getJson($previewUrl)->assertOk()
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.first_party.linked_organization_id', $superior->id)
            ->assertJsonPath('data.second_party.linked_organization_id', $context->organization->id);
        $created = $this->withHeaders($context->authHeaders())->postJson($url, $payload)->assertCreated();
        $contract = Contract::findOrFail($created->json('data.id'));
        self::assertSame($superior->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
        self::assertSame($context->organization->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
    }

    public function test_shared_contract_http_keeps_notes_private_without_granting_project_access(): void
    {
        $owner = AdminApiTestContext::create();
        $executor = AdminApiTestContext::create();
        $outsider = AdminApiTestContext::create();
        $this->allowAdminAccess();
        $project = Project::factory()->create(['organization_id' => $owner->organization->id]);
        $contractor = $this->createContractor($owner->organization, 'Connected executor', $executor->organization);
        $contract = $this->createContract($owner->organization, $project, $contractor, ['notes' => 'Legacy owner secret']);
        app(\App\Services\Contract\ContractPartySnapshotService::class)->syncParties($contract);
        app(\App\Services\Contract\ContractOrganizationViewService::class)->synchronizeNewContract($contract);
        $viewUrl = '/api/v1/admin/contracts/'.$contract->id.'/organization-view';
        $this->withHeaders($owner->authHeaders())->patchJson($viewUrl, ['private_notes' => 'Owner budget memo', 'version' => 1])
            ->assertOk()->assertJsonPath('data.private_notes', 'Owner budget memo');
        $otherView = $this->withHeaders($executor->authHeaders())->getJson($viewUrl)->assertOk();
        $otherView->assertJsonPath('data.private_notes', null)->assertJsonPath('data.version', 1);
        $this->withHeaders($executor->authHeaders())->patchJson($viewUrl, [
            'private_notes' => 'Executor memo', 'version' => 1, 'organization_id' => $owner->organization->id,
        ])->assertOk()->assertJsonPath('data.organization_id', $executor->organization->id);
        $ownerView = $this->withHeaders($owner->authHeaders())->getJson($viewUrl)->assertOk();
        $ownerView->assertJsonPath('data.private_notes', 'Owner budget memo');
        self::assertSame($ownerView->json('data.legal_contract'), $otherView->json('data.legal_contract'));
        $shared = $this->withHeaders($executor->authHeaders())->getJson('/api/v1/admin/contracts/'.$contract->id)->assertOk();
        self::assertArrayNotHasKey('notes', $shared->json('data'));
        self::assertArrayNotHasKey('payments', $shared->json('data'));
        $projectResponse = $this->withHeaders($executor->authHeaders())->getJson('/api/v1/admin/projects/'.$project->id);
        self::assertTrue(in_array($projectResponse->status(), [403, 404], true), $projectResponse->getContent());
        $this->withHeaders($outsider->authHeaders())->getJson($viewUrl)->assertNotFound();
        self::assertSame(1, Contract::whereKey($contract->id)->count());
        self::assertSame(0, PaymentDocument::where('invoiceable_type', Contract::class)->where('invoiceable_id', $contract->id)->count());
        self::assertFalse($project->organizations()->where('organizations.id', $executor->organization->id)->exists());
    }

    public function test_shared_directory_http_uses_linked_executor_and_rejects_unavailable_record(): void
    {
        $context = AdminApiTestContext::create();
        $directory = Organization::factory()->verified()->create();
        $executor = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $shared = $this->createContractor($directory, 'Shared executor', $executor);
        $denied = $this->createContractor($directory, 'Unavailable executor');
        $sharing = \Mockery::mock(\App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface::class);
        $sharing->shouldReceive('canUseContractor')->with($shared->id, $context->organization->id)->andReturn(true);
        $sharing->shouldReceive('canUseContractor')->with($denied->id, $context->organization->id)->andReturn(false);
        $this->app->instance(\App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface::class, $sharing);
        $this->allowAdminAccess();
        $url = "/api/v1/admin/projects/{$project->id}/contracts";
        $payload = [
            'project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'expense',
            'contractor_id' => $denied->id, 'number' => 'HTTP-SHARED', 'date' => '2026-09-19',
            'subject' => 'Shared directory contract', 'base_amount' => 1000, 'total_amount' => 1000,
            'is_fixed_amount' => true, 'status' => 'draft', 'idempotency_key' => 'http-shared',
        ];
        $this->withHeaders($context->authHeaders())->postJson($url, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('contractor_id');
        self::assertSame(0, Contract::where('organization_id', $context->organization->id)->count());
        $response = $this->withHeaders($context->authHeaders())->postJson($url, [
            ...$payload, 'contractor_id' => $shared->id,
        ])->assertCreated();
        $contract = Contract::findOrFail($response->json('data.id'));
        self::assertSame($context->organization->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
        self::assertSame($executor->id, $contract->secondParty()->firstOrFail()->linked_organization_id);
        self::assertSame($shared->id, $contract->contractor_id);
        self::assertNotNull($contract->legal_archive_document_id);
    }

    public function test_external_executor_http_keeps_external_party_without_linking_directory_owner(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $external = $this->createContractor($context->organization, 'External executor');
        $this->allowAdminAccess();
        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts", [
                'project_id' => $project->id, 'contract_side_type' => 'subcontract', 'direction' => 'expense',
                'contractor_id' => $external->id, 'number' => 'HTTP-EXTERNAL', 'date' => '2026-09-19',
                'subject' => 'External executor contract', 'base_amount' => 1000, 'total_amount' => 1000,
                'is_fixed_amount' => true, 'status' => 'draft', 'idempotency_key' => 'http-external',
            ])->assertCreated();
        $contract = Contract::findOrFail($response->json('data.id'));
        self::assertSame($context->organization->id, $contract->firstParty()->firstOrFail()->linked_organization_id);
        $party = $contract->secondParty()->firstOrFail();
        self::assertNull($party->linked_organization_id);
        self::assertSame($external->name, $party->name);
        self::assertSame($external->id, $contract->contractor_id);
        self::assertNotNull($contract->legal_archive_document_id);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contractSideInputs')]
    public function test_owner_can_create_update_list_and_archive_contract_inside_project(string $sideInput): void
    {
        $context = AdminApiTestContext::create(organizationAttributes: ['registration_number' => '325169000191393']);
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Contract Owner Project',
        ]);
        $anotherProject = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Another Owner Project',
        ]);
        $contractor = $this->createContractor($context->organization, 'Owner Contractor');
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts", [
                'project_id' => $project->id,
                'contract_side_type' => $sideInput,
                'idempotency_key' => 'contract-core-'.$sideInput,
                'contractor_id' => $contractor->id,
                'number' => 'CON-001',
                'date' => '2026-06-01',
                'subject' => 'Engineering systems installation',
                'work_type_category' => ContractWorkTypeCategoryEnum::INSTALLATION->value,
                'is_fixed_amount' => true,
                'base_amount' => 500000,
                'total_amount' => 500000,
                'gp_percentage' => 5,
                'planned_advance_amount' => 100000,
                'actual_advance_amount' => 25000,
                'status' => ContractStatusEnum::ACTIVE->value,
                'start_date' => '2026-06-05',
                'end_date' => '2026-08-20',
                'notes' => 'Owner contract notes',
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.project_id', $project->id);
        $createResponse->assertJsonPath('data.number', 'CON-001');
        $createResponse->assertJsonPath('data.contractor_id', $contractor->id);

        $contract = Contract::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame($context->organization->id, $contract->organization_id);
        $this->assertSame($project->id, $contract->project_id);
        self::assertNull($contract->firstParty?->kpp);
        self::assertSame('325169000191393', $contract->firstParty?->ogrn);
        $this->assertSame(500000.0, (float) $contract->base_amount);
        $this->assertSame(500000.0, (float) $contract->total_amount);
        $this->assertSame(100000.0, (float) $contract->planned_advance_amount);
        $this->assertSame(25000.0, (float) $contract->actual_advance_amount);

        $otherProjectContract = $this->createContract($context->organization, $anotherProject, $contractor, [
            'number' => 'CON-OTHER-PROJECT',
        ]);

        $indexResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts?per_page=20&sort_by=number&sort_direction=asc");

        $indexResponse->assertOk();
        $indexResponse->assertJsonPath('success', true);
        $contractIds = collect($indexResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($contract->id, $contractIds);
        $this->assertNotContains($otherProjectContract->id, $contractIds);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}", [
                'number' => 'CON-001-UPD',
                'contract_side_type' => $sideInput,
                'subject' => 'Updated contract subject',
                'base_amount' => 650000,
                'total_amount' => 650000,
                'planned_advance_amount' => 150000,
                'actual_advance_amount' => 75000,
                'status' => ContractStatusEnum::ON_HOLD->value,
                'notes' => null,
            ]);

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('success', true);
        $updateResponse->assertJsonPath('data.number', 'CON-001-UPD');
        $contract->refresh();
        $this->assertSame(650000.0, (float) $contract->total_amount);
        $this->assertSame(75000.0, (float) $contract->actual_advance_amount);
        $this->assertNull($contract->notes);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}/archive");

        $deleteResponse->assertOk();
        self::assertSame('archived', $contract->fresh()->status->value);
    }

    public static function contractSideInputs(): array
    {
        return [['contract'], ['general_contractor_to_contractor'], ['general_contract'], ['customer_to_general_contractor']];
    }

    public function test_contract_details_include_effective_payment_documents_in_financial_summary(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
        ]);
        $contractor = $this->createContractor($context->organization, 'Payment Summary Contractor');
        $contract = $this->createContract($context->organization, $project, $contractor);

        $paidDocument = PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'source_type' => Contract::class,
            'source_id' => $contract->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'CONTRACT-PAID-001',
            'document_date' => '2026-06-10',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 1800,
            'paid_amount' => 1800,
            'remaining_amount' => 0,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::PAID,
            'due_date' => '2026-06-10',
        ]);
        PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'source_type' => Contract::class,
            'source_id' => $contract->id,
            'document_type' => PaymentDocumentType::INVOICE,
            'document_number' => 'CONTRACT-CANCELLED-001',
            'document_date' => '2026-06-11',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => 900,
            'paid_amount' => 0,
            'remaining_amount' => 900,
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::CANCELLED,
            'due_date' => '2026-06-11',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}");

        $response->assertOk();
        $response->assertJsonPath('data.financial_summary.payments_count', 1);
        $response->assertJsonPath('data.financial_summary.payments_total_amount', 1800);
        $response->assertJsonPath('data.payments.0.id', $paidDocument->id);
    }

    public function test_contract_update_and_delete_are_hidden_when_contract_belongs_to_another_project(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $anotherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Project Scope Contractor');
        $contract = $this->createContract($context->organization, $anotherProject, $contractor, [
            'number' => 'OUT-OF-PROJECT',
        ]);
        $this->allowAdminAccess();

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}", [
                'number' => 'SHOULD-NOT-CHANGE',
            ]);

        $updateResponse->assertForbidden();
        $this->assertSame('OUT-OF-PROJECT', $contract->fresh()->number);

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}");

        $deleteResponse->assertConflict();
        $this->assertNotSoftDeleted('contracts', ['id' => $contract->id]);
    }

    public function test_contract_create_rejects_foreign_project_and_contractor_ids(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignContractor = $this->createContractor($foreignOrganization, 'Foreign Contract Contractor');
        $this->allowAdminAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts", [
                'project_id' => $foreignProject->id,
                'contract_side_type' => ContractSideTypeEnum::CONTRACT->value,
                'contractor_id' => null,
                'is_self_execution' => true,
                'number' => 'FOREIGN-PROJECT',
                'date' => '2026-06-01',
                'status' => ContractStatusEnum::ACTIVE->value,
            ]);

        $foreignProjectResponse->assertStatus(422);
        $foreignProjectResponse->assertJsonValidationErrors('project_id');

        $foreignContractorResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts", [
                'project_id' => $project->id,
                'contract_side_type' => ContractSideTypeEnum::CONTRACT->value,
                'contractor_id' => $foreignContractor->id,
                'number' => 'FOREIGN-CONTRACTOR',
                'date' => '2026-06-01',
                'status' => ContractStatusEnum::ACTIVE->value,
            ]);

        $foreignContractorResponse->assertStatus(422);
        $foreignContractorResponse->assertJsonValidationErrors('contractor_id');
    }

    public function test_multi_project_contract_create_rejects_foreign_project_ids(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $contractor = $this->createContractor($context->organization, 'Multi Project Contractor');
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/contracts", [
                'contract_side_type' => ContractSideTypeEnum::CONTRACT->value,
                'contractor_id' => $contractor->id,
                'number' => 'FOREIGN-MULTI-PROJECT',
                'date' => '2026-06-01',
                'status' => ContractStatusEnum::ACTIVE->value,
                'is_multi_project' => true,
                'project_ids' => [$project->id, $foreignProject->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('project_ids.1');
    }

    public function test_contract_update_rejects_foreign_project_and_contractor_ids(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $contractor = $this->createContractor($context->organization, 'Update Contract Contractor');
        $contract = $this->createContract($context->organization, $project, $contractor, [
            'number' => 'UPD-FOREIGN-GUARD',
        ]);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignContractor = $this->createContractor($foreignOrganization, 'Foreign Update Contractor');
        $this->allowAdminAccess();

        $foreignProjectResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}", [
                'project_id' => $foreignProject->id,
            ]);

        $foreignProjectResponse->assertStatus(422);
        $foreignProjectResponse->assertJsonValidationErrors('project_id');

        $foreignContractorResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}", [
                'contractor_id' => $foreignContractor->id,
            ]);

        $foreignContractorResponse->assertStatus(422);
        $foreignContractorResponse->assertJsonValidationErrors('contractor_id');
    }

    public function test_contractor_participant_sees_only_own_contracts_and_cannot_mutate_other_contracts(): void
    {
        $participantContext = AdminApiTestContext::create();
        $otherParticipantOrganization = Organization::factory()->verified()->create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Participant Contract Project',
        ]);
        $this->attachProjectParticipant($project, $participantContext->organization, ProjectOrganizationRole::CONTRACTOR);
        $this->attachProjectParticipant($project, $otherParticipantOrganization, ProjectOrganizationRole::CONTRACTOR);

        $ownContractor = $this->createContractor($ownerOrganization, 'Own Participant Contractor', $participantContext->organization);
        $otherContractor = $this->createContractor($ownerOrganization, 'Other Participant Contractor', $otherParticipantOrganization);
        $ownContract = $this->createContract($ownerOrganization, $project, $ownContractor, [
            'number' => 'OWN-CONTRACT',
        ]);
        $otherContract = $this->createContract($ownerOrganization, $project, $otherContractor, [
            'number' => 'OTHER-CONTRACT',
        ]);
        $this->allowAdminAccess();

        $indexResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts?per_page=20");

        $indexResponse->assertOk();
        $indexIds = collect($indexResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($ownContract->id, $indexIds);
        $this->assertNotContains($otherContract->id, $indexIds);

        $ownShowResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts/{$ownContract->id}");
        $ownShowResponse->assertOk();
        $ownShowResponse->assertJsonPath('data.id', $ownContract->id);

        $otherShowResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts/{$otherContract->id}");
        $otherShowResponse->assertNotFound();

        $otherUpdateResponse = $this->withHeaders($participantContext->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/contracts/{$otherContract->id}", [
                'number' => 'LEAKED-UPDATE',
            ]);
        $otherUpdateResponse->assertNotFound();
        $this->assertSame('OTHER-CONTRACT', $otherContract->fresh()->number);

        $otherDeleteResponse = $this->withHeaders($participantContext->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/contracts/{$otherContract->id}");
        $otherDeleteResponse->assertConflict();
        $this->assertNotSoftDeleted('contracts', ['id' => $otherContract->id]);
    }

    public function test_contractor_participant_can_open_contract_owned_by_current_organization(): void
    {
        $participantContext = AdminApiTestContext::create();
        $ownerOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create([
            'organization_id' => $ownerOrganization->id,
            'name' => 'Participant Owned Contract Project',
        ]);
        $this->attachProjectParticipant($project, $participantContext->organization, ProjectOrganizationRole::CONTRACTOR);

        $ownerAsContractor = $this->createContractor(
            $participantContext->organization,
            'Owner Organization Counterparty',
            $ownerOrganization
        );
        $contract = $this->createContract($participantContext->organization, $project, $ownerAsContractor, [
            'number' => 'PARTICIPANT-OWNED',
        ]);
        $this->allowAdminAccess();

        $indexResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts?per_page=20");

        $indexResponse->assertOk();
        $indexIds = collect($indexResponse->json('data.data'))->pluck('id')->all();
        $this->assertContains($contract->id, $indexIds);

        $showResponse = $this->withHeaders($participantContext->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/contracts/{$contract->id}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $contract->id);
        $showResponse->assertJsonPath('data.organization_id', $participantContext->organization->id);
    }

    private function createContractor(
        Organization $organization,
        string $name,
        ?Organization $sourceOrganization = null
    ): Contractor {
        return Contractor::query()->create([
            'organization_id' => $organization->id,
            'source_organization_id' => $sourceOrganization?->id,
            'name' => $name,
            'contact_person' => $name.' Manager',
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
            'inn' => (string) random_int(1000000000, 9999999999),
            'contractor_type' => $sourceOrganization
                ? ContractorType::INVITED_ORGANIZATION->value
                : ContractorType::MANUAL->value,
            'connected_at' => $sourceOrganization ? now() : null,
        ]);
    }

    private function createContract(
        Organization $organization,
        Project $project,
        Contractor $contractor,
        array $overrides = []
    ): Contract {
        return Contract::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::CONTRACT->value,
            'number' => 'CON-'.random_int(10000, 99999),
            'date' => '2026-06-01',
            'subject' => 'Contract subject',
            'work_type_category' => ContractWorkTypeCategoryEnum::SMR->value,
            'base_amount' => 300000,
            'total_amount' => 300000,
            'gp_percentage' => 0,
            'planned_advance_amount' => 0,
            'actual_advance_amount' => 0,
            'status' => ContractStatusEnum::ACTIVE->value,
            'start_date' => '2026-06-01',
            'end_date' => '2026-09-01',
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ], $overrides));
    }

    private function attachProjectParticipant(
        Project $project,
        Organization $organization,
        ProjectOrganizationRole $role
    ): void {
        $project->organizations()->attach($organization->id, [
            'role' => $role === ProjectOrganizationRole::SUBCONTRACTOR ? 'child_contractor' : $role->value,
            'role_new' => $role->value,
            'is_active' => true,
            'invited_at' => now(),
            'accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowAdminAccess(): void
    {
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
                }
            );
        });
    }
}
