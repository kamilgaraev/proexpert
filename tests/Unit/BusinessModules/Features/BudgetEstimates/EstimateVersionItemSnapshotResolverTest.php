<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionItemSnapshotResolver;
use App\Models\EstimateItem;
use App\Models\EstimateVersion;
use PHPUnit\Framework\TestCase;

class EstimateVersionItemSnapshotResolverTest extends TestCase
{
    public function test_resolves_nested_item_by_stable_identity_from_immutable_snapshot(): void
    {
        $version = new EstimateVersion;
        $version->snapshot = [
            'sections' => [[
                'items' => [],
                'children' => [[
                    'items' => [[
                        'id' => 45,
                        'stable_key' => 'item-stable-45',
                        'name' => 'Historical work',
                        'quantity' => '3.00000000',
                        'unit_price' => '125.55',
                        'total_amount' => '376.65',
                    ]],
                    'children' => [],
                ]],
            ]],
            'unsectioned_items' => [],
        ];
        $item = new EstimateItem(['stable_key' => 'item-stable-45']);
        $item->id = 999;

        $snapshot = (new EstimateVersionItemSnapshotResolver)->resolve($version, $item);

        self::assertSame(45, $snapshot['id']);
        self::assertSame('125.55', $snapshot['unit_price']);
        self::assertSame('376.65', $snapshot['total_amount']);
    }
}
