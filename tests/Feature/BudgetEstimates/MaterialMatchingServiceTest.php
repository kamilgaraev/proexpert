<?php

declare(strict_types=1);

namespace Tests\Feature\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\MaterialMatchingService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\NormativeCodeService;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reuses_existing_measurement_unit_by_short_name_when_creating_material(): void
    {
        $organization = Organization::factory()->create();
        $unit = MeasurementUnit::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('LOWER(short_name) = ?', ['шт'])
            ->firstOrFail();
        $unitsCount = MeasurementUnit::query()->where('organization_id', $organization->id)->count();

        $service = new MaterialMatchingService(new NormativeCodeService());

        $material = $service->findOrCreate(
            'ФСБЦ-20.2.07.04-0001',
            'Лоток кабельный',
            $unit->short_name,
            100.0,
            $organization->id
        );

        $this->assertSame($unit->id, $material->measurement_unit_id);
        $this->assertSame($unitsCount, MeasurementUnit::query()->where('organization_id', $organization->id)->count());
    }

    public function test_reuses_existing_measurement_unit_when_truncated_short_name_conflicts(): void
    {
        $organization = Organization::factory()->create();
        $unit = MeasurementUnit::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Existing long unit',
            'short_name' => 'abcdefghij',
            'type' => 'material',
        ]);
        $unitsCount = MeasurementUnit::query()->where('organization_id', $organization->id)->count();

        $service = new MaterialMatchingService(new NormativeCodeService());

        $material = $service->findOrCreate(
            'FSBC-20.2.07.04-0002',
            'Cable tray',
            'abcdefghij extra',
            100.0,
            $organization->id
        );

        $this->assertSame($unit->id, $material->measurement_unit_id);
        $this->assertSame($unitsCount, MeasurementUnit::query()->where('organization_id', $organization->id)->count());
    }
}
