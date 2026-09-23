<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Exceptions\ContractBuilderException;
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

    public function test_financial_guard_rejects_total_below_approved_acts(): void
    {
        $project = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto($project->id));
        $contract->performanceActs()->create([
            'project_id' => $project->id,
            'act_document_number' => 'ACT-FINANCIAL-100',
            'act_date' => now()->toDateString(),
            'amount' => 1100,
            'status' => 'approved',
            'is_approved' => true,
        ]);

        $this->expectException(ContractBuilderException::class);
        app(\App\Services\Contract\ContractBuilderMutationGuard::class)
            ->assertUpdate($contract->fresh(), ['total_amount' => 900], 'update');
    }

    public function test_financial_guard_rejects_planned_advance_below_actual_advance(): void
    {
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto());
        $contract->update(['actual_advance_amount' => 250]);

        $this->expectException(ContractBuilderException::class);
        app(\App\Services\Contract\ContractBuilderMutationGuard::class)
            ->assertUpdate($contract->fresh(), ['planned_advance_amount' => 200], 'update');
    }

    public function test_financial_guard_keeps_planned_advance_at_or_below_contract_total(): void
    {
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto());
        $contract->update(['planned_advance_amount' => 700]);

        $this->expectException(ContractBuilderException::class);
        app(\App\Services\Contract\ContractBuilderMutationGuard::class)
            ->assertUpdate($contract->fresh(), ['total_amount' => 600], 'update');
    }

    public function test_financial_guard_rejects_direct_advance_override_when_payment_document_exists(): void
    {
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto());
        \App\BusinessModules\Core\Payments\Models\PaymentDocument::create([
            'organization_id' => $this->organizationId,
            'document_type' => 'invoice',
            'document_number' => 'ADV-GUARD-'.$contract->id,
            'document_date' => now()->toDateString(),
            'direction' => 'outgoing',
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'invoice_type' => 'advance',
            'amount' => 300,
            'currency' => 'RUB',
            'paid_amount' => 300,
            'remaining_amount' => 0,
            'status' => 'paid',
        ]);

        $this->expectException(ContractBuilderException::class);
        app(\App\Services\Contract\ContractBuilderMutationGuard::class)
            ->assertUpdate($contract->fresh(), ['actual_advance_amount' => 250], 'update');
    }

    public function test_financial_guard_rejects_actual_advance_without_payment_documents(): void
    {
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto());

        $this->expectException(ContractBuilderException::class);
        app(\App\Services\Contract\ContractBuilderMutationGuard::class)
            ->assertUpdate($contract->fresh(), ['actual_advance_amount' => 250], 'update');
    }

    public function test_financial_guard_counts_completed_payment_linked_to_contract_act(): void
    {
        $project = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto($project->id));
        $act = $contract->performanceActs()->create([
            'project_id' => $project->id,
            'act_document_number' => 'ACT-PAYMENT-100',
            'act_date' => now()->toDateString(),
            'amount' => 100,
            'status' => 'approved',
            'is_approved' => true,
        ]);
        $document = \App\BusinessModules\Core\Payments\Models\PaymentDocument::create([
            'organization_id' => $this->organizationId,
            'document_type' => 'invoice',
            'document_number' => 'ACT-PAY-GUARD-'.$contract->id,
            'document_date' => now()->toDateString(),
            'direction' => 'outgoing',
            'invoiceable_type' => \App\Models\ContractPerformanceAct::class,
            'invoiceable_id' => $act->id,
            'amount' => 600,
            'currency' => 'RUB',
            'paid_amount' => 0,
            'remaining_amount' => 600,
            'status' => 'approved',
        ]);
        \App\BusinessModules\Core\Payments\Models\PaymentTransaction::create([
            'organization_id' => $this->organizationId,
            'payment_document_id' => $document->id,
            'amount' => 600,
            'currency' => 'RUB',
            'payment_method' => 'bank_transfer',
            'transaction_date' => now()->toDateString(),
            'status' => 'completed',
        ]);

        $this->expectException(ContractBuilderException::class);
        app(\App\Services\Contract\ContractBuilderMutationGuard::class)
            ->assertUpdate($contract->fresh(), ['total_amount' => 500], 'update');
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

    public static function executionCases(): array
    {
        return [['contractor', 'act'], ['contractor', 'payment'], ['supplier', 'act'], ['supplier', 'payment']];
    }

    public function test_indirect_party_change_is_rolled_back_after_execution(): void
    {
        $project = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto($project->id));
        $originalParty = $contract->secondParty()->firstOrFail()->toArray();
        $contract->performanceActs()->create([
            'project_id' => $project->id, 'act_document_number' => 'ACT-INDIRECT',
            'act_date' => '2026-09-19', 'amount' => 100, 'status' => 'draft',
        ]);
        $snapshots = Mockery::mock(\App\Services\Contract\ContractPartySnapshotService::class);
        $snapshots->shouldReceive('syncParties')->once()->andReturnUsing(static function (Contract $updated): void {
            $updated->secondParty()->update(['inn' => '7709999999']);
        });
        $this->app->instance(\App\Services\Contract\ContractPartySnapshotService::class, $snapshots);
        try {
            $this->mutationService()->update($contract->id, $this->organizationId, $this->contractDto($project->id));
            self::fail('Indirect changes to executed parties must roll back');
        } catch (\Exception $exception) {
            self::assertSame(trans_message('contracts.parties_locked_by_execution'), $exception->getMessage());
            self::assertSame($originalParty, $contract->secondParty()->firstOrFail()->toArray());
        }
    }

    public function test_ordinary_update_preserves_executed_party_after_directory_change(): void
    {
        $project = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $contract = $this->mutationService()->create($this->organizationId, $this->contractDto($project->id));
        $originalParty = $contract->secondParty()->firstOrFail()->toArray();
        $contract->performanceActs()->create([
            'project_id' => $project->id, 'act_document_number' => 'ACT-PRESERVE',
            'act_date' => '2026-09-19', 'amount' => 100, 'status' => 'draft',
        ]);
        \App\Models\Contractor::whereKey($this->contractorId)->update(['name' => 'Новое имя в справочнике']);
        $this->mutationService()->update($contract->id, $this->organizationId, $this->contractDto($project->id));
        self::assertSame($originalParty, $contract->secondParty()->firstOrFail()->toArray());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('executionCases')]
    public function test_replacing_executor_with_same_contract_type_is_rejected_after_execution(string $executor, string $execution): void
    {
        $project = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $service = $this->mutationService();
        $supplierId = $executor === 'supplier' ? \App\Models\Supplier::create([
            'organization_id' => $this->organizationId, 'name' => 'Поставщик',
        ])->id : null;
        $contract = $service->create($this->organizationId, $this->contractDto($project->id, $supplierId));
        $originalExecutorId = $contract->getAttribute($executor . '_id');
        $originalParty = $contract->secondParty()->firstOrFail()->toArray();
        if ($execution === 'act') {
            $contract->performanceActs()->create([
            'project_id' => $project->id,
            'act_document_number' => 'ACT-LOCK-1',
            'act_date' => '2026-09-19',
            'amount' => 100,
            'status' => 'draft',
            ]);
        } else {
            $contract->payments()->create([
                'organization_id' => $this->organizationId, 'project_id' => $project->id,
                'invoiceable_type' => Contract::class, 'document_number' => 'PAY-LOCK-1',
                'document_date' => '2026-09-19', 'document_type' => 'invoice',
                'direction' => 'outgoing', 'invoice_type' => 'act',
                'amount' => 100, 'currency' => 'RUB', 'status' => 'draft',
            ]);
        }
        $this->contractorId = \App\Models\Contractor::create([
            'organization_id' => $this->organizationId, 'name' => 'Другой подрядчик', 'contractor_type' => 'manual',
        ])->id;
        if ($supplierId !== null) {
            $supplierId = \App\Models\Supplier::create([
                'organization_id' => $this->organizationId, 'name' => 'Другой поставщик',
            ])->id;
        }

        try {
            $service->update($contract->id, $this->organizationId, $this->contractDto($project->id, $supplierId));
            self::fail('Executed contract parties must not change');
        } catch (\Exception $exception) {
            self::assertSame(trans_message('contracts.parties_locked_by_execution'), $exception->getMessage());
            self::assertSame($originalExecutorId, $contract->fresh()->getAttribute($executor . '_id'));
            self::assertSame($originalParty, $contract->secondParty()->firstOrFail()->toArray());
        }
    }

    public function test_self_execution_expense_is_created_for_multiple_projects(): void
    {
        $project = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $secondProject = \App\Models\Project::factory()->create(['organization_id' => $this->organizationId]);
        $dto = new ContractDTO(...[
            ...get_object_vars($this->contractDto($project->id)),
            'number' => 'SELF-EXPENSE-100',
            'contractor_id' => null,
            'status' => ContractStatusEnum::DRAFT,
            'is_self_execution' => true,
            'is_multi_project' => true,
            'project_ids' => [$project->id, $secondProject->id],
            'direction' => 'expense',
        ]);
        $contract = $this->mutationService()->create($this->organizationId, $dto)->fresh();
        self::assertTrue($contract->is_self_execution);
        self::assertTrue($contract->is_multi_project);
        self::assertSame(ContractStatusEnum::DRAFT, $contract->status);
        self::assertSame($this->organizationId, $contract->firstParty->linked_organization_id);
        self::assertSame($this->organizationId, $contract->secondParty->linked_organization_id);
        self::assertEqualsCanonicalizing([$project->id, $secondProject->id], $contract->projects()->pluck('projects.id')->all());
        self::assertSame('expense', app(\App\Services\Contract\ContractSideResolverService::class)->resolve($contract, $this->organizationId)['direction']);
    }

    private function contractDto(?int $projectId = null, ?int $supplierId = null): ContractDTO
    {
        return new ContractDTO(
            project_id: $projectId,
            contractor_id: $supplierId === null ? $this->contractorId : null,
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
            supplier_id: $supplierId,
            contract_side_type: $supplierId === null ? ContractSideTypeEnum::CONTRACT : ContractSideTypeEnum::GENERAL_CONTRACTOR_SUPPLY,
        );
    }
}
