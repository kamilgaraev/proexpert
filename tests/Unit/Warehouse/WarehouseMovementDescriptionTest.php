<?php

declare(strict_types=1);

namespace Tests\Unit\Warehouse;

use App\BusinessModules\Features\BasicWarehouse\Http\Resources\WarehouseMovementResource;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WarehouseMovementDescriptionTest extends TestCase
{
    public static function descriptions(): array
    {
        return [
            'flat' => [['description' => "Полное описание\nВторая строка"], "Полное описание\nВторая строка"],
            'nested' => [['metadata' => ['description' => 'Ранее сохранено']], 'Ранее сохранено'],
            'empty' => [[], null],
            'invalid' => [['description' => ['unexpected']], null],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_history_and_receipt_response_include_description(array $metadata, ?string $expected): void
    {
        $movement = $this->getMockBuilder(WarehouseMovement::class)->onlyMethods(['getAttribute'])->getMock();
        $values = [
            'id' => 1,
            'metadata' => $metadata,
            'warehouse' => (object) ['name' => 'Склад'],
            'material' => (object) ['name' => 'Материал', 'code' => 'M', 'measurementUnit' => (object) ['short_name' => 'шт']],
            'quantity' => 2,
            'price' => 15,
            'movement_date' => Carbon::parse('2026-09-09 17:21:16'),
            'photo_gallery' => [],
        ];
        $movement->method('getAttribute')->willReturnCallback(static fn ($key) => $values[$key] ?? null);
        $service = $this->getMockBuilder(WarehouseService::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $history = (new ReflectionMethod(WarehouseService::class, 'serializeMovement'))->invoke($service, $movement, null, null);
        $receipt = (new WarehouseMovementResource($movement))->toArray(new Request());

        self::assertSame($expected, $history['description']);
        self::assertSame($expected, $receipt['description']);
        self::assertSame(30.0, $history['total_value']);
    }
}
