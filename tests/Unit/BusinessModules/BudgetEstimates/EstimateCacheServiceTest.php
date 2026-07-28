<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateStructureSnapshotStorage;
use App\Models\Estimate;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EstimateCacheServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_invalidate_structure_clears_runtime_cache_and_snapshot_file(): void
    {
        $estimate = (new Estimate())->forceFill([
            'id' => 42,
            'organization_id' => 7,
            'structure_cache_path' => 'org-7/estimates/42/structure_snapshot.json',
        ]);

        Cache::shouldReceive('forget')
            ->once()
            ->with('estimate_structure_42');

        $snapshotStorage = Mockery::mock(EstimateStructureSnapshotStorage::class);
        $snapshotStorage->shouldReceive('delete')
            ->once()
            ->with('org-7/estimates/42/structure_snapshot.json')
            ->andReturnNull();

        (new EstimateCacheService($snapshotStorage))->invalidateStructure($estimate);

        $this->assertNull($estimate->structure_cache_path);
    }
}
