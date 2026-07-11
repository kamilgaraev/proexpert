<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\FinalizationDeliveryReceipt;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryFinalizationDeliveryStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FinalizationDeliveryStoreTest extends TestCase
{
    public function test_business_delivery_is_exactly_once_and_crash_before_commit_is_replayable(): void
    {
        $store = new InMemoryFinalizationDeliveryStore;
        $receipt = $this->receipt();
        $calls = 0;

        try {
            $store->deliverOnce($receipt, static function () use (&$calls): never {
                $calls++;
                throw new RuntimeException('crash');
            });
        } catch (RuntimeException) {
        }
        $store->deliverOnce($receipt, static function () use (&$calls): object {
            $calls++;

            return new \stdClass;
        });
        $store->deliverOnce($receipt, static function () use (&$calls): object {
            $calls++;

            return new \stdClass;
        });

        self::assertSame(2, $calls);
    }

    public function test_same_business_key_cannot_cross_tenant_scope(): void
    {
        $store = new InMemoryFinalizationDeliveryStore;
        $store->deliverOnce($this->receipt(), static fn (): object => new \stdClass);

        $this->expectException(RuntimeException::class);
        $store->deliverOnce(new FinalizationDeliveryReceipt(
            11, 20, 30, '018f4a20-3f4c-7a11-8a22-123456789abc',
            'estimate_generation_completed', 40, str_repeat('a', 64),
        ), static fn (): object => new \stdClass);
    }

    private function receipt(): FinalizationDeliveryReceipt
    {
        return new FinalizationDeliveryReceipt(
            10, 20, 30, '018f4a20-3f4c-7a11-8a22-123456789abc',
            'estimate_generation_completed', 40, str_repeat('a', 64),
        );
    }
}
