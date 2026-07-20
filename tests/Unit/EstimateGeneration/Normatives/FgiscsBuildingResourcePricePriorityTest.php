<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsBuildingResourcePriceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePricePriority;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\FgiscsBuildingResourcePriceUpdateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

class FgiscsBuildingResourcePricePriorityTest extends TestCase
{
    public function test_direct_price_wins_over_index_price_regardless_of_import_order(): void
    {
        $priority = new FgiscsBuildingResourcePricePriority;
        $indexed = $this->price(120.0, 'regional_building_resource_index');
        $direct = $this->price(150.0, 'regional_building_resource_direct');

        self::assertSame($direct, $priority->preferred($indexed, $direct));
        self::assertSame($direct, $priority->preferred($direct, $indexed));
        self::assertTrue($priority->shouldReplace('regional_building_resource_index', $direct));
        self::assertFalse($priority->shouldReplace('regional_building_resource_direct', $indexed));
    }

    public function test_import_contract_uses_bounded_batches_in_price_priority_order(): void
    {
        $reflection = new ReflectionClass(FgiscsBuildingResourcePriceUpdateService::class);

        self::assertSame(1000, $reflection->getConstant('UPSERT_BATCH_SIZE'));
        self::assertSame([
            'regional_building_resource_index',
            'regional_building_resource_export',
            'regional_building_resource_direct',
        ], $reflection->getConstant('SOURCE_KINDS'));

        $source = file_get_contents($reflection->getFileName());

        self::assertIsString($source);
        self::assertStringContainsString('count($batches[$price->sourcePriceKind]) >= self::UPSERT_BATCH_SIZE', $source);
        self::assertStringContainsString('EstimateResourcePrice::query()->upsert(', $source);
        self::assertStringContainsString('$this->persistImportProgress(', $source);
        self::assertStringNotContainsString('DB::transaction(function () use ($spoolPaths', $source);
        self::assertStringContainsString("'building_resources_imported' => false", $source);
        self::assertStringContainsString("'building_resources_imported',\n            \$force,", $source);
        self::assertStringContainsString("'building_resources_already_imported'", $source);
        self::assertStringContainsString('&& (int) $regionalVersion->rows_imported > 0', $source);
        self::assertStringContainsString('&& $this->hasImportedBuildingResources($regionalVersion)', $source);
        self::assertStringContainsString('private function hasImportedBuildingResources(', $source);
        self::assertStringContainsString('public function syncAllRegions(', $source);
        self::assertStringContainsString("'fgiscs_building_resource_region_update_failed'", $source);
        self::assertStringContainsString("\$this->lifecycleService->finalize(\n                    \$regionalVersion", $source);
        self::assertStringContainsString('$regionalVersion->refresh();', $source);
        self::assertStringContainsString("'project_material_conjuncture_checked' => true", $source);
        self::assertStringContainsString("'project_material_conjuncture_complete' => \$conjunctureStats['missing'] === 0", $source);
        self::assertStringContainsString('$this->conjuncturePrices->import(', $source);
        self::assertLessThan(
            strrpos($source, '$this->lifecycleService->finalize('),
            strpos($source, '$this->conjuncturePrices->import('),
        );
        self::assertStringNotContainsString('Residential project material conjuncture analysis is incomplete.', $source);
        self::assertStringContainsString('$this->recordImportFailure(', $source);
        self::assertStringContainsString("Log::warning('[EstimateGeneration] Failed to record building resource import failure status.'", $source);
        self::assertStringContainsString("'exception_class' => \$statusException::class", $source);
        self::assertStringContainsString('throw $exception;', $source);
        self::assertStringNotContainsString('catch (\\Throwable)', $source);
    }

    public function test_failure_status_update_cannot_mask_original_import_failure(): void
    {
        $service = (new ReflectionClass(FgiscsBuildingResourcePriceUpdateService::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(FgiscsBuildingResourcePriceUpdateService::class, 'attemptFailureStatusUpdate');
        $attempted = false;

        $method->invoke($service, 'dataset_version', static function () use (&$attempted): never {
            $attempted = true;

            throw new RuntimeException('immutable status');
        });

        self::assertTrue($attempted);
    }

    private function price(float $value, string $sourcePriceKind): FgiscsBuildingResourcePriceDTO
    {
        return new FgiscsBuildingResourcePriceDTO(
            code: '02.1.01.02-0003',
            name: 'Material',
            unit: 'm3',
            currentPrice: $value,
            sourcePriceKind: $sourcePriceKind,
        );
    }
}
