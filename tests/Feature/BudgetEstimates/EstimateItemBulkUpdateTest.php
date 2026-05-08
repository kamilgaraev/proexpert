<?php

declare(strict_types=1);

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;

it('scales normative child resources when parent quantity is updated in bulk', function (): void {
    Queue::fake();

    $organization = Organization::factory()->create();
    $project = Project::factory()->create(['organization_id' => $organization->id]);

    $estimate = Estimate::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'number' => 'BULK-001',
        'name' => 'Bulk update estimate',
        'type' => 'local',
        'status' => 'draft',
        'estimate_date' => now()->toDateString(),
        'calculation_method' => 'resource',
        'overhead_rate' => 0,
        'profit_rate' => 0,
        'vat_rate' => 0,
    ]);

    $work = EstimateItem::query()->create([
        'estimate_id' => $estimate->id,
        'item_type' => EstimatePositionItemType::WORK->value,
        'position_number' => '1',
        'name' => 'Normative work',
        'quantity' => 1,
        'quantity_total' => 1,
        'unit_price' => 20,
        'direct_costs' => 20,
        'total_amount' => 20,
        'current_total_amount' => 20,
        'is_manual' => false,
        'metadata' => ['normative_source' => 'estimate_norms'],
    ]);

    $child = EstimateItem::query()->create([
        'estimate_id' => $estimate->id,
        'parent_work_id' => $work->id,
        'item_type' => EstimatePositionItemType::MATERIAL->value,
        'position_number' => '1.1',
        'name' => 'Resource',
        'quantity' => 2,
        'quantity_total' => 2,
        'unit_price' => 10,
        'direct_costs' => 20,
        'materials_cost' => 20,
        'total_amount' => 20,
        'current_total_amount' => 20,
        'is_manual' => false,
        'metadata' => ['quantity_per_unit' => 2],
    ]);

    EstimateItemResource::query()->create([
        'estimate_item_id' => $work->id,
        'resource_type' => 'material',
        'name' => 'Resource',
        'quantity_per_unit' => 2,
        'total_quantity' => 2,
        'unit_price' => 10,
        'total_amount' => 20,
    ]);

    $items = app(EstimateItemService::class)->bulkUpdate($estimate, [
        ['id' => $work->id, 'quantity' => 3],
    ]);

    expect($items)->toHaveCount(1);

    $work->refresh();
    $child->refresh();
    $resource = EstimateItemResource::query()->where('estimate_item_id', $work->id)->firstOrFail();

    expect((float) $work->quantity)->toBe(3.0)
        ->and((float) $work->direct_costs)->toBe(60.0)
        ->and((float) $work->total_amount)->toBe(60.0)
        ->and((float) $child->quantity)->toBe(6.0)
        ->and((float) $child->total_amount)->toBe(60.0)
        ->and((float) $resource->total_quantity)->toBe(6.0)
        ->and((float) $resource->total_amount)->toBe(60.0)
        ->and((float) $estimate->fresh()->total_amount)->toBe(60.0);
});
