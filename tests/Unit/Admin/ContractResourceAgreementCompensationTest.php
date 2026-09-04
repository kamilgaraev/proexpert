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
use Tests\TestCase;

final class ContractResourceAgreementCompensationTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_superseding_an_agreement_preserves_its_carried_amount_in_the_summary(): void
    {
        foreach ([0, -500] as $change) {
            $resolver = Mockery::mock(ContractSideResolverService::class);
            $resolver->shouldReceive('resolveCustomerAlias')->andReturn(null);
            $resolver->shouldReceive('resolve')->andReturn([]);
            $this->app->instance(ContractSideResolverService::class, $resolver);

            $contract = new class extends Contract {
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
            $service->shouldReceive('getCurrentState')->with($contract)->andReturn(['total_amount' => $total]);
            $service->shouldReceive('getTimeline')->with($contract)->andReturn($events);
            $this->app->instance(ContractStateEventService::class, $service);

            $payload = (new ContractResource($contract))->toArray(Request::create('/'));

            self::assertSame($total, $payload['total_amount']);
            self::assertEquals($total, $payload['financial_summary']['total_amount_with_agreements']);
            self::assertEquals(500 + $change, $payload['financial_summary']['agreements_total_change']);
            self::assertEquals(3000, $payload['financial_summary']['base_amount']);
            self::assertSame($total, $payload['remaining_amount']);
        }
    }

    private function event(ContractStateEventTypeEnum $type, int $amount, bool $active = true, bool $compensating = false): ContractStateEvent
    {
        $event = new class extends ContractStateEvent {
            public bool $activeForTest = true;

            public function isActive(): bool
            {
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
