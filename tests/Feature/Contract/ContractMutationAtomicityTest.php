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
use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Services\Contract\ContractAccessService;
use App\Services\Contract\ContractPartySnapshotService;
use App\Services\Contract\ContractPaymentDocumentService;
use App\Services\Contract\ContractSideMutationService;
use App\Services\Contract\ContractStateEventService;
use App\Services\Contractor\SelfExecutionService;
use App\Services\Logging\LoggingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ContractMutationAtomicityTest extends TestCase
{
    public function refreshDatabase(): void
    {
        Schema::dropIfExists('contract_state_events');
        Schema::dropIfExists('contracts');

        Schema::create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('contract_side_type')->nullable();
            $table->string('contract_category')->nullable();
            $table->string('number');
            $table->date('date');
            $table->string('subject')->nullable();
            $table->decimal('base_amount', 18, 2)->nullable();
            $table->decimal('total_amount', 18, 2)->nullable();
            $table->string('status');
            $table->boolean('is_fixed_amount')->default(true);
            $table->boolean('is_multi_project')->default(false);
            $table->boolean('is_self_execution')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contract_state_events', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('event_type');
            $table->string('triggered_by_type')->nullable();
            $table->unsignedBigInteger('triggered_by_id')->nullable();
            $table->unsignedBigInteger('specification_id')->nullable();
            $table->decimal('amount_delta', 18, 2)->default(0);
            $table->date('effective_from')->nullable();
            $table->unsignedBigInteger('supersedes_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Contract::flushEventListeners();
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
            $this->mutationService()->create(1, $this->contractDto());
        } finally {
            self::assertFalse(Contract::query()->where('number', 'ATOMIC-100')->exists());
        }
    }

    public function test_retrieving_contract_does_not_recalculate_or_persist_price(): void
    {
        $contract = Contract::create([
            'organization_id' => 1,
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

        (new ContractObserver())->retrieved($contract->fresh());

        $storedAmount = Contract::withoutEvents(
            static fn () => Contract::query()->whereKey($contract->id)->value('total_amount')
        );

        self::assertSame('1000.00', (string) $storedAmount);
    }

    private function mutationService(): ContractSideMutationService
    {
        $repository = Mockery::mock(ContractRepositoryInterface::class);
        $repository->shouldReceive('create')
            ->once()
            ->andReturnUsing(static fn (array $data): Contract => Contract::create(array_intersect_key($data, array_flip([
                'organization_id',
                'project_id',
                'contractor_id',
                'supplier_id',
                'contract_side_type',
                'contract_category',
                'number',
                'date',
                'subject',
                'base_amount',
                'total_amount',
                'status',
                'is_fixed_amount',
                'is_multi_project',
                'is_self_execution',
            ]))));

        $snapshotService = Mockery::mock(ContractPartySnapshotService::class);
        $snapshotService->shouldReceive('syncParties')->once();

        $contractorSharing = Mockery::mock(ContractorSharingInterface::class);
        $contractorSharing->shouldReceive('canUseContractor')->andReturnTrue();

        return new ContractSideMutationService(
            $repository,
            Mockery::mock(ContractPaymentDocumentService::class),
            Mockery::mock(ContractAccessService::class),
            Mockery::mock(LoggingService::class)->shouldIgnoreMissing(),
            $contractorSharing,
            Mockery::mock(SelfExecutionService::class),
            app(ContractStateEventService::class),
            $snapshotService,
        );
    }

    private function contractDto(): ContractDTO
    {
        return new ContractDTO(
            project_id: null,
            contractor_id: 1,
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
            contract_side_type: ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
        );
    }
}
