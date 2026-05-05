<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionComparisonService;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class EstimateVersionComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compare_detects_added_removed_and_changed_items_by_stable_key(): void
    {
        $estimate = $this->createEstimate(['total_amount' => 1000]);
        $versionA = $this->createVersion($estimate, 1, $this->snapshot([
            $this->item('work-1', 'struct-work-1', '1', 'Old work', 'm2', '2.00000000', '500.00', '1000.00'),
            $this->item('removed-1', 'struct-removed-1', '2', 'Removed work', 'm', '1.00000000', '200.00', '200.00'),
            $this->item('same-1', 'struct-same-1', '3', 'Same work', 'pcs', '1.00000000', '50.00', '50.00'),
        ], totalAmount: '1000.00'), totalAmount: '1000.00');
        $versionB = $this->createVersion($estimate, 2, $this->snapshot([
            $this->item('work-1', 'struct-work-1', '1', 'Old work', 'm2', '2.00000000', '575.00', '1150.00'),
            $this->item('same-1', 'struct-same-1', '3', 'Same work', 'pcs', '1.00000000', '50.00', '50.00'),
            $this->item('added-1', 'struct-added-1', '4', 'Added work', 'kg', '3.00000000', '100.00', '300.00'),
        ], totalAmount: '1150.00'), totalAmount: '1150.00');

        $result = app(EstimateVersionComparisonService::class)->compare($versionA, $versionB);

        $this->assertSame(1, $result['summary']['added']);
        $this->assertSame(1, $result['summary']['removed']);
        $this->assertSame(1, $result['summary']['changed']);
        $this->assertSame(1, $result['summary']['unchanged']);
        $this->assertSame('150.00', $result['summary']['total_delta_amount']);
        $this->assertSame('added', $result['added'][0]['diff_type']);
        $this->assertSame('added', $result['added'][0]['_diff']);
        $this->assertSame('added-1', $result['added'][0]['stable_key']);
        $this->assertSame('removed', $result['removed'][0]['diff_type']);
        $this->assertSame('removed-1', $result['removed'][0]['stable_key']);
        $this->assertSame('changed', $result['changed'][0]['diff_type']);
        $this->assertSame('work-1', $result['changed'][0]['stable_key']);
        $this->assertSame('500.00', $result['changed'][0]['changes']['unit_price']['before']);
        $this->assertSame('575.00', $result['changed'][0]['changes']['unit_price']['after']);
        $this->assertSame('75.00', $result['changed'][0]['changes']['unit_price']['delta']);
        $this->assertSame('150.00', $result['changed'][0]['changes']['total_amount']['delta']);
    }

    public function test_compare_refuses_versions_from_different_estimates(): void
    {
        $versionA = $this->createVersion($this->createEstimate(), 1, $this->snapshot([]));
        $versionB = $this->createVersion($this->createEstimate(), 1, $this->snapshot([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Нельзя сравнивать версии разных смет');

        app(EstimateVersionComparisonService::class)->compare($versionA, $versionB);
    }

    public function test_compare_flattens_nested_section_children_and_item_children(): void
    {
        $estimate = $this->createEstimate(['total_amount' => 100]);
        $versionA = $this->createVersion($estimate, 1, [
            'schema_version' => 1,
            'totals' => ['total_amount' => '100.00'],
            'sections' => [[
                'stable_key' => 'section-root',
                'items' => [],
                'children' => [[
                    'stable_key' => 'section-child',
                    'items' => [[
                        ...$this->item('parent-work', 'parent-struct', '1', 'Parent work', 'm2', '1.00000000', '100.00', '100.00'),
                        'children' => [
                            $this->item('child-resource', 'child-struct', '1.1', 'Resource', 'kg', '2.00000000', '10.00', '20.00'),
                        ],
                    ]],
                    'children' => [],
                ]],
            ]],
            'unsectioned_items' => [],
        ], totalAmount: '100.00');
        $versionB = $this->createVersion($estimate, 2, [
            'schema_version' => 1,
            'totals' => ['total_amount' => '115.00'],
            'sections' => [[
                'stable_key' => 'section-root',
                'items' => [],
                'children' => [[
                    'stable_key' => 'section-child',
                    'items' => [[
                        ...$this->item('parent-work', 'parent-struct', '1', 'Parent work', 'm2', '1.00000000', '100.00', '100.00'),
                        'children' => [
                            $this->item('child-resource', 'child-struct', '1.1', 'Resource', 'kg', '3.00000000', '10.00', '30.00'),
                        ],
                    ]],
                    'children' => [],
                ]],
            ]],
            'unsectioned_items' => [],
        ], totalAmount: '115.00');

        $result = app(EstimateVersionComparisonService::class)->compare($versionA, $versionB);

        $this->assertSame(1, $result['summary']['changed']);
        $this->assertSame(1, $result['summary']['unchanged']);
        $this->assertSame('child-resource', $result['changed'][0]['stable_key']);
        $this->assertSame('1.00000000', $result['changed'][0]['changes']['quantity']['delta']);
        $this->assertSame('10.00', $result['changed'][0]['changes']['total_amount']['delta']);
    }

    public function test_compare_matches_by_structural_key_when_stable_key_is_absent(): void
    {
        $estimate = $this->createEstimate(['total_amount' => 100]);
        $versionA = $this->createVersion($estimate, 1, $this->snapshot([
            $this->item(null, 'item:root:section:1::work:structural work', '1', 'Structural work', 'm2', '1.00000000', '100.00', '100.00'),
        ], totalAmount: '100.00'), totalAmount: '100.00');
        $versionB = $this->createVersion($estimate, 2, $this->snapshot([
            $this->item(null, 'item:root:section:1::work:structural work', '1', 'Structural work', 'm2', '1.50000000', '100.00', '150.00'),
        ], totalAmount: '150.00'), totalAmount: '150.00');

        $result = app(EstimateVersionComparisonService::class)->compare($versionA, $versionB);

        $this->assertSame(0, $result['summary']['added']);
        $this->assertSame(0, $result['summary']['removed']);
        $this->assertSame(1, $result['summary']['changed']);
        $this->assertSame('item:root:section:1::work:structural work', $result['changed'][0]['stable_key']);
        $this->assertSame('structural_key', $result['changed'][0]['match_key_type']);
        $this->assertSame('0.50000000', $result['changed'][0]['changes']['quantity']['delta']);
    }

    private function createVersion(Estimate $estimate, int $versionNumber, array $snapshot, string $totalAmount = '0.00'): EstimateVersion
    {
        return EstimateVersion::query()->create([
            'estimate_id' => $estimate->id,
            'organization_id' => $estimate->organization_id,
            'version_number' => $versionNumber,
            'label' => 'Version ' . $versionNumber,
            'snapshot_type' => 'manual',
            'estimate_status' => 'draft',
            'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'total_amount' => $totalAmount,
            'total_amount_with_vat' => $totalAmount,
            'total_direct_costs' => $totalAmount,
        ]);
    }

    private function snapshot(array $items, string $totalAmount = '0.00'): array
    {
        return [
            'schema_version' => 1,
            'totals' => ['total_amount' => $totalAmount],
            'sections' => [[
                'stable_key' => 'section',
                'items' => $items,
                'children' => [],
            ]],
            'unsectioned_items' => [],
        ];
    }

    private function item(
        ?string $stableKey,
        ?string $structuralKey,
        string $positionNumber,
        string $name,
        string $unit,
        string $quantity,
        string $unitPrice,
        string $totalAmount
    ): array {
        return [
            'stable_key' => $stableKey,
            'structural_key' => $structuralKey,
            'position_number' => $positionNumber,
            'name' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'quantity_total' => $quantity,
            'unit_price' => $unitPrice,
            'current_unit_price' => $unitPrice,
            'total_amount' => $totalAmount,
            'current_total_amount' => $totalAmount,
            'direct_costs' => $totalAmount,
            'materials_cost' => '0.00',
            'machinery_cost' => '0.00',
            'labor_cost' => '0.00',
            'equipment_cost' => '0.00',
            'overhead_amount' => '0.00',
            'profit_amount' => '0.00',
            'children' => [],
        ];
    }

    private function createEstimate(array $overrides = []): Estimate
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);

        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'EST-' . (DB::table('estimates')->count() + 1),
            'name' => 'Test estimate',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-05-05',
            'base_price_date' => '2026-05-01',
            'total_direct_costs' => 0,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_equipment_costs' => 0,
            'total_amount' => 0,
            'total_amount_with_vat' => 0,
            'vat_rate' => 20,
            'overhead_rate' => 0,
            'profit_rate' => 0,
            'calculation_method' => 'resource',
        ], $overrides));
    }
}
