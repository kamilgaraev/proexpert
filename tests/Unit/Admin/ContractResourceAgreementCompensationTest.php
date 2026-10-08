<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Services\Contract\ContractSideResolverService;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ContractResourceAgreementCompensationTest extends TestCase
{
    public function refreshDatabase(): void {}

    public static function eventLoadingModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('eventLoadingModes')]
    public function test_superseding_an_agreement_preserves_its_carried_amount_in_the_summary(bool $eventsLoaded): void
    {
        foreach ([0, -500] as $change) {
            $resolver = Mockery::mock(ContractSideResolverService::class);
            $resolver->shouldReceive('resolveCustomerAlias')->andReturn(null);
            $resolver->shouldReceive('resolve')->andReturn([]);
            $this->app->instance(ContractSideResolverService::class, $resolver);

            $contract = new class extends Contract
            {
                public function usesEventSourcing(): bool
                {
                    return true;
                }
            };
            $total = 3500.0 + $change;
            $contract->setRawAttributes([
                'id' => 274,
                'organization_id' => 75,
                'number' => 'Учебный договор',
                'status' => ContractStatusEnum::DRAFT->value,
                'base_amount' => 3000,
                'total_amount' => $total,
                'is_fixed_amount' => true,
                'created_at' => '2026-09-04 08:00:00',
                'updated_at' => '2026-09-04 08:00:00',
            ], true);

            $events = collect([
                $this->event(ContractStateEventTypeEnum::CREATED, 3000),
                $this->event(ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED, 500, false),
                $this->event(ContractStateEventTypeEnum::AMENDED, 500, true, true),
                $this->event(ContractStateEventTypeEnum::AMENDED, $change),
                $this->event(ContractStateEventTypeEnum::PAYMENT_CREATED, 100),
                $this->event(ContractStateEventTypeEnum::SUPERSEDED, 0),
            ]);
            $service = Mockery::mock(ContractStateEventService::class);
            $service->shouldReceive($eventsLoaded ? 'getCurrentStateFromLoadedEvents' : 'getCurrentState')
                ->withArgs($eventsLoaded ? [$contract, Mockery::type(\Illuminate\Support\Collection::class)] : [$contract])
                ->andReturn(['total_amount' => $total]);
            if ($eventsLoaded) {
                foreach ($events as $event) {
                    $event->setAttribute('superseded_by_events_exists', ! $event->isActive());
                    $event->failOnActivityCheck = true;
                }
                $contract->setRelation('stateEvents', new \Illuminate\Database\Eloquent\Collection($events->all()));
                $service->shouldNotReceive('getTimeline');
            } else {
                $service->shouldReceive('getTimeline')->with($contract)->andReturn($events);
            }
            $this->app->instance(ContractStateEventService::class, $service);

            $payload = (new ContractResource($contract))->toArray(Request::create('/'));

            self::assertSame($total, $payload['total_amount']);
            self::assertEquals($total, $payload['financial_summary']['total_amount_with_agreements']);
            self::assertEquals(500 + $change, $payload['financial_summary']['agreements_total_change']);
            self::assertEquals(3000, $payload['financial_summary']['base_amount']);
            self::assertSame($total, $payload['remaining_amount']);
        }
    }

    public function test_loaded_events_preserve_the_legacy_fallback_when_current_state_fails(): void
    {
        $resolver = Mockery::mock(ContractSideResolverService::class);
        $resolver->shouldReceive('resolveCustomerAlias')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn([]);
        $this->app->instance(ContractSideResolverService::class, $resolver);
        $contract = new Contract;
        $contract->setRawAttributes([
            'id' => 275, 'organization_id' => 75, 'status' => ContractStatusEnum::DRAFT->value,
            'base_amount' => 1000, 'total_amount' => 1200, 'is_fixed_amount' => true,
            'created_at' => '2026-09-04 08:00:00', 'updated_at' => '2026-09-04 08:00:00',
        ], true);
        $contract->setRelation('stateEvents', new \Illuminate\Database\Eloquent\Collection([
            $this->event(ContractStateEventTypeEnum::CREATED, 1000),
        ]));
        $contract->setRelation('agreements', collect([new SupplementaryAgreement(['change_amount' => 200])]));
        $service = Mockery::mock(ContractStateEventService::class);
        $service->shouldReceive('getCurrentStateFromLoadedEvents')
            ->with($contract, Mockery::type(\Illuminate\Support\Collection::class))
            ->once()->andThrow(new \RuntimeException('State unavailable'));
        $service->shouldNotReceive('getTimeline');
        $this->app->instance(ContractStateEventService::class, $service);
        $payload = (new ContractResource($contract))->toArray(Request::create('/'));
        self::assertSame(1400.0, $payload['total_amount']);
        self::assertEquals(1200, $payload['financial_summary']['base_amount']);
        self::assertEquals(200, $payload['financial_summary']['agreements_total_change']);
    }

    private function event(ContractStateEventTypeEnum $type, int $amount, bool $active = true, bool $compensating = false): ContractStateEvent
    {
        $event = new class extends ContractStateEvent
        {
            public bool $activeForTest = true;

            public bool $failOnActivityCheck = false;

            public function isActive(): bool
            {
                if ($this->failOnActivityCheck) {
                    throw new \LogicException('Loaded list events must not perform activity lookups');
                }

                return $this->activeForTest;
            }
        };
        $event->activeForTest = $active;
        $event->setRawAttributes([
            'event_type' => $type->value,
            'amount_delta' => $amount,
            'triggered_by_type' => $type === ContractStateEventTypeEnum::CREATED ? Contract::class : SupplementaryAgreement::class,
            'metadata' => json_encode(['is_compensating' => $compensating], JSON_THROW_ON_ERROR),
            'created_at' => '2026-09-04 08:00:00',
        ], true);

        return $event;
    }
}
