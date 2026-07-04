<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\DTOs\Contract\ContractDTO;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Contract\ContractPartyRoleEnum;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Activity\ActivityEvent;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Counterparty;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractSideMutationService;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ContractSideReviewTest extends TestCase
{
    public function test_customer_to_general_contractor_contract_uses_external_customer_counterparty_snapshot(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'ООО Генподрядчик',
            'tax_number' => '7701000001',
            'registration_number' => '770101001',
        ]);

        $customer = Counterparty::create([
            'organization_id' => $organization->id,
            'name' => 'ООО Заказчик',
            'legal_name' => 'Общество с ограниченной ответственностью Заказчик',
            'inn' => '7702000002',
            'kpp' => '770201001',
            'roles' => ['customer'],
            'source' => 'manual',
            'is_active' => true,
        ]);

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'customer_counterparty_id' => $customer->id,
        ]);

        $contract = app(ContractSideMutationService::class)->create(
            $organization->id,
            new ContractDTO(
                project_id: $project->id,
                contractor_id: null,
                parent_contract_id: null,
                number: 'CUST-GC-100',
                date: now()->toDateString(),
                subject: 'Договор генподряда',
                work_type_category: null,
                payment_terms: null,
                base_amount: 250000.0,
                total_amount: 250000.0,
                gp_percentage: null,
                gp_calculation_type: GpCalculationTypeEnum::PERCENTAGE,
                gp_coefficient: null,
                warranty_retention_calculation_type: null,
                warranty_retention_percentage: null,
                warranty_retention_coefficient: null,
                subcontract_amount: null,
                planned_advance_amount: null,
                actual_advance_amount: null,
                status: ContractStatusEnum::ACTIVE,
                start_date: null,
                end_date: null,
                notes: null,
                contract_side_type: ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR,
            )
        );

        $contract->load(['firstParty', 'secondParty']);

        $this->assertSame($organization->id, $contract->organization_id);
        $this->assertNull($contract->contractor_id);
        $this->assertSame($customer->id, $contract->firstParty?->counterparty_id);
        $this->assertSame(ContractPartyRoleEnum::CUSTOMER, $contract->firstParty?->role);
        $this->assertSame('ООО Заказчик', $contract->firstParty?->name);
        $this->assertSame($organization->id, $contract->secondParty?->linked_organization_id);
        $this->assertSame(ContractPartyRoleEnum::GENERAL_CONTRACTOR, $contract->secondParty?->role);
        $this->assertSame('ООО Генподрядчик', $contract->secondParty?->name);
    }

    public function test_contract_creation_records_activity_event(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['name' => 'Иван']);
        Auth::login($user);

        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);

        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'ООО Подрядчик',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        $contract = app(ContractSideMutationService::class)->create(
            $organization->id,
            new ContractDTO(
                project_id: $project->id,
                contractor_id: $contractor->id,
                parent_contract_id: null,
                number: 'ACT-100',
                date: now()->toDateString(),
                subject: 'Тестовый договор',
                work_type_category: null,
                payment_terms: null,
                base_amount: 100000.0,
                total_amount: 100000.0,
                gp_percentage: null,
                gp_calculation_type: GpCalculationTypeEnum::PERCENTAGE,
                gp_coefficient: null,
                warranty_retention_calculation_type: GpCalculationTypeEnum::PERCENTAGE,
                warranty_retention_percentage: null,
                warranty_retention_coefficient: null,
                subcontract_amount: null,
                planned_advance_amount: null,
                actual_advance_amount: null,
                status: ContractStatusEnum::ACTIVE,
                start_date: null,
                end_date: null,
                notes: null,
                contract_side_type: ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
            )
        );

        $events = ActivityEvent::query()
            ->where('event_type', 'contract.created')
            ->where('subject_id', $contract->id);

        $this->assertSame(1, $events->count());

        $event = $events->firstOrFail();

        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('contracts', $event->module);
        $this->assertSame(ActivityActionEnum::Created->value, $event->action);
        $this->assertSame($project->id, $event->project_id);
        $this->assertSame('ACT-100', $event->subject_label);
    }

    public function test_review_resolution_clears_flags_and_rebinds_customer_side_contract_to_project_customer(): void
    {
        $generalContractorOrganization = Organization::factory()->create();
        $customerOrganization = Organization::factory()->create();

        $participantService = app(ProjectParticipantService::class);
        $mutationService = app(ContractSideMutationService::class);

        $project = Project::factory()->create([
            'organization_id' => $generalContractorOrganization->id,
        ]);

        $customer = Counterparty::create([
            'organization_id' => $generalContractorOrganization->id,
            'linked_organization_id' => $customerOrganization->id,
            'name' => $customerOrganization->name,
            'inn' => $customerOrganization->tax_number,
            'kpp' => $customerOrganization->registration_number,
            'roles' => ['customer'],
            'source' => 'project_participant',
            'is_active' => true,
        ]);

        $project->update(['customer_counterparty_id' => $customer->id]);

        $participantService->attach($project, $customerOrganization->id, ProjectOrganizationRole::CUSTOMER);

        $contractor = Contractor::create([
            'organization_id' => $customerOrganization->id,
            'source_organization_id' => $generalContractorOrganization->id,
            'name' => 'ООО Генподрядчик',
            'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
        ]);

        $contract = Contract::create([
            'organization_id' => $generalContractorOrganization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'requires_contract_side_review' => true,
            'contract_side_review_reason' => 'ambiguous_backfill',
            'number' => 'CR-101',
            'date' => now()->toDateString(),
            'subject' => 'Тестовый договор',
            'total_amount' => 150000,
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        $resolved = $mutationService->resolveReview(
            $contract->id,
            $generalContractorOrganization->id,
            ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
        );

        $this->assertSame($generalContractorOrganization->id, $resolved->organization_id);
        $this->assertNull($resolved->contractor_id);
        $this->assertSame(ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR, $resolved->contract_side_type);
        $this->assertFalse((bool) $resolved->requires_contract_side_review);
        $this->assertNull($resolved->contract_side_review_reason);

        $resolved->load(['firstParty', 'secondParty']);

        $this->assertSame($customer->id, $resolved->firstParty?->counterparty_id);
        $this->assertSame($generalContractorOrganization->id, $resolved->secondParty?->linked_organization_id);
    }
}
