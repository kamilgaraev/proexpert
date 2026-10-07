<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\Specification;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContractLoadedStateProjectionTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_loaded_projection_preserves_amount_specification_and_postgres_null_ordering(): void
    {
        $contract = $this->contract(1600);
        $created = $this->event(1, ContractStateEventTypeEnum::CREATED, 1000, '2026-01-01', '2026-01-01 08:00:00');
        $earlier = $this->event(2, ContractStateEventTypeEnum::AMENDED, 100, '2026-02-01', '2026-03-03 08:00:00', 11);
        $later = $this->event(3, ContractStateEventTypeEnum::AMENDED, 200, '2026-02-01', '2026-03-04 08:00:00', 12);
        $nullDate = $this->event(4, ContractStateEventTypeEnum::AMENDED, 300, null, '2026-01-01 08:00:00', 13);
        $superseded = $this->event(5, ContractStateEventTypeEnum::AMENDED, 500, '2026-02-05', '2026-03-05 08:00:00', 14, true);
        $payment = $this->event(6, ContractStateEventTypeEnum::PAYMENT_CREATED, 99, '2026-03-01', '2026-03-06 08:00:00');
        $repository = Mockery::mock(ContractStateEventRepositoryInterface::class);
        $repository->shouldReceive('findActiveEvents')->with(7, ['specification', 'createdBy'])
            ->once()->andReturn(collect([$created, $earlier, $later, $payment, $nullDate]));
        $service = new ContractStateEventService($repository);
        $legacy = $service->getCurrentState($contract);
        $loaded = $service->getCurrentStateFromLoadedEvents($contract, collect([$nullDate, $superseded, $later, $payment, $earlier, $created]));
        self::assertSame($legacy['total_amount'], $loaded['total_amount']);
        self::assertSame(1600.0, $loaded['total_amount']);
        self::assertSame($legacy['active_specification'], $loaded['active_specification']);
        self::assertSame(13, $loaded['active_specification']->id);
        self::assertSame([1, 2, 3, 6, 4], $loaded['active_events']->pluck('id')->all());
        self::assertSame($legacy['active_events']->pluck('id')->all(), $loaded['active_events']->pluck('id')->all());
    }

    public function test_loaded_projection_preserves_the_mismatch_audit_context(): void
    {
        $contract = $this->contract(1000);
        $created = $this->event(1, ContractStateEventTypeEnum::CREATED, 1000, '2026-01-01', '2026-01-01 08:00:00');
        $amended = $this->event(2, ContractStateEventTypeEnum::AMENDED, 200, '2026-02-01', '2026-02-01 08:00:00');
        $payment = $this->event(3, ContractStateEventTypeEnum::PAYMENT_CREATED, 50, '2026-03-01', '2026-03-01 08:00:00');
        $superseded = $this->event(4, ContractStateEventTypeEnum::AMENDED, 500, '2026-02-01', '2026-02-01 08:00:00', null, true);
        $contexts = [];
        Log::shouldReceive('warning')->twice()->with('Contract state amount mismatch detected', Mockery::on(static function (array $context) use (&$contexts): bool {
            $contexts[] = $context;

            return true;
        }));
        $repository = Mockery::mock(ContractStateEventRepositoryInterface::class);
        $repository->shouldReceive('findActiveEvents')->with(7, ['specification', 'createdBy'])
            ->once()->andReturn(collect([$created, $amended, $payment]));
        $service = new ContractStateEventService($repository);
        $legacy = $service->getCurrentState($contract);
        $loaded = $service->getCurrentStateFromLoadedEvents($contract, collect([$payment, $superseded, $amended, $created]));
        self::assertSame(1200.0, $loaded['total_amount']);
        self::assertSame($legacy['total_amount'], $loaded['total_amount']);
        self::assertCount(2, $contexts);
        self::assertSame($contexts[0], $contexts[1]);
        self::assertSame(200.0, $contexts[1]['difference']);
        self::assertSame(3, $contexts[1]['active_events_count']);
    }

    public function test_null_creation_time_sorts_last_and_preserves_the_selected_specification(): void
    {
        $contract = $this->contract(300);
        $dated = $this->event(1, ContractStateEventTypeEnum::AMENDED, 100, '2026-02-01', '2026-02-01 08:00:00', 12);
        $nullCreated = $this->event(2, ContractStateEventTypeEnum::AMENDED, 200, '2026-02-01', null, 13);
        $repository = Mockery::mock(ContractStateEventRepositoryInterface::class);
        $repository->shouldReceive('findActiveEvents')->with(7, ['specification', 'createdBy'])
            ->once()->andReturn(collect([$dated, $nullCreated]));
        $service = new ContractStateEventService($repository);
        $legacy = $service->getCurrentState($contract);
        $loaded = $service->getCurrentStateFromLoadedEvents($contract, collect([$nullCreated, $dated]));
        self::assertSame($legacy['active_specification'], $loaded['active_specification']);
        self::assertSame(13, $loaded['active_specification']->id);
        self::assertSame([1, 2], $loaded['active_events']->pluck('id')->all());
    }

    public static function incompleteEvents(): array
    {
        return [['foreign'], ['missing_exists'], ['missing_specification'], ['missing_creator']];
    }

    #[DataProvider('incompleteEvents')]
    public function test_incomplete_or_foreign_loaded_events_use_the_unchanged_repository_path(string $mode): void
    {
        $contract = $this->contract(1000);
        $trusted = $this->event(1, ContractStateEventTypeEnum::CREATED, 1000, '2026-01-01', '2026-01-01 08:00:00');
        $incomplete = $this->event(2, ContractStateEventTypeEnum::CREATED, 9999, '2026-01-01', '2026-01-01 08:00:00');
        match ($mode) {
            'foreign' => $incomplete->setAttribute('contract_id', 8),
            'missing_exists' => $incomplete->offsetUnset('superseded_by_events_exists'),
            'missing_specification' => $incomplete->unsetRelation('specification'),
            'missing_creator' => $incomplete->unsetRelation('createdBy'),
        };
        $repository = Mockery::mock(ContractStateEventRepositoryInterface::class);
        $repository->shouldReceive('findActiveEvents')->with(7, ['specification', 'createdBy'])
            ->once()->andReturn(new Collection([$trusted]));
        $state = (new ContractStateEventService($repository))->getCurrentStateFromLoadedEvents($contract, collect([$incomplete]));
        self::assertSame(1000.0, $state['total_amount']);
        self::assertSame([1], $state['active_events']->pluck('id')->all());
    }

    private function contract(int $total): Contract
    {
        $contract = new Contract;
        $contract->setRawAttributes(['id' => 7, 'number' => 'STATE-TEST', 'total_amount' => $total], true);

        return $contract;
    }

    private function event(int $id, ContractStateEventTypeEnum $type, int $amount, ?string $effective, ?string $created, ?int $specificationId = null, bool $superseded = false): ContractStateEvent
    {
        $event = new ContractStateEvent;
        $event->setRawAttributes([
            'id' => $id, 'contract_id' => 7, 'event_type' => $type->value,
            'amount_delta' => $amount, 'effective_from' => $effective, 'created_at' => $created,
            'specification_id' => $specificationId, 'superseded_by_events_exists' => $superseded,
        ], true);
        $specification = null;
        if ($specificationId !== null) {
            $specification = new Specification;
            $specification->setRawAttributes(['id' => $specificationId], true);
        }
        $event->setRelation('specification', $specification);
        $event->setRelation('createdBy', null);

        return $event;
    }
}
