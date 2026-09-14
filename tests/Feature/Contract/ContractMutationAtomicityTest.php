<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Observers\ContractObserver;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Services\Contract\ContractSideMutationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ContractMutationAtomicityTest extends TestCase
{
    use \Tests\Support\EnablesImmutableAuditWriter;

    private int $organizationId;
    private int $contractorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableImmutableAuditWriter();
        $this->organizationId = \App\Models\Organization::factory()->create()->id;
        $this->contractorId = \App\Models\Contractor::create([
            'organization_id' => $this->organizationId, 'name' => 'Подрядчик', 'contractor_type' => 'manual',
        ])->id;
    }

    public function test_contract_is_rolled_back_when_required_state_event_cannot_be_created(): void
    {
        $eventRepository = Mockery::mock(ContractStateEventRepositoryInterface::class);
        $eventRepository->shouldReceive('createEvent')
            ->once()
            ->andThrow(new RuntimeException('state event unavailable'));
        $this->app->instance(ContractStateEventRepositoryInterface::class, $eventRepository);

        $this->expectException(RuntimeException::class);

        try {
            $this->mutationService()->create($this->organizationId, $this->contractDto());
        } finally {
            self::assertFalse(Contract::query()->where('number', 'ATOMIC-100')->exists());
        }
    }

    public function test_retrieving_contract_does_not_recalculate_or_persist_price(): void
    {
        $contract = Contract::create([
            'organization_id' => $this->organizationId,
            'project_id' => null,
            'number' => 'READ-ONLY-100',
            'date' => now()->toDateString(),
            'subject' => 'Проверка чтения',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        ContractStateEvent::create([
            'contract_id' => $contract->id,
            'event_type' => ContractStateEventTypeEnum::CREATED,
            'triggered_by_type' => Contract::class,
            'triggered_by_id' => $contract->id,
            'amount_delta' => '500.00',
            'effective_from' => now(),
        ]);

        app(ContractObserver::class)->retrieved($contract->fresh());

        $storedAmount = Contract::withoutEvents(
            static fn () => Contract::query()->whereKey($contract->id)->value('total_amount')
        );

        self::assertSame('1000.00', (string) $storedAmount);
    }

    public function test_contract_and_audit_are_atomic(): void
    {
        $audit = Mockery::mock(LegalDocumentAudit::class);
        $audit->shouldReceive('recordContractForActorId')
            ->once()
            ->andThrow(new RuntimeException('audit unavailable'));

        $this->expectException(RuntimeException::class);

        try {
            $this->mutationService($audit)->create($this->organizationId, $this->contractDto());
        } finally {
            self::assertFalse(Contract::query()->where('number', 'ATOMIC-100')->exists());
        }
    }

    private function mutationService(?LegalDocumentAudit $audit = null): ContractSideMutationService
    {
        if ($audit !== null) {
            $this->app->instance(LegalDocumentAudit::class, $audit);
        }

        return app(ContractSideMutationService::class);
    }

    private function contractDto(): ContractDTO
    {
        return new ContractDTO(
            project_id: null,
            contractor_id: $this->contractorId,
            parent_contract_id: null,
            number: 'ATOMIC-100',
            date: now()->toDateString(),
            subject: 'Атомарный договор',
            work_type_category: null,
            payment_terms: null,
            base_amount: 1000.0,
            total_amount: 1000.0,
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
            contract_side_type: ContractSideTypeEnum::CONTRACT,
        );
    }
}
