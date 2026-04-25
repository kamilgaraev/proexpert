<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCacheService;
use App\Models\Estimate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\TestCase;

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

        Storage::shouldReceive('disk')
            ->once()
            ->with('s3')
            ->andReturnSelf();

        Storage::shouldReceive('exists')
            ->once()
            ->with('org-7/estimates/42/structure_snapshot.json')
            ->andReturnTrue();

        Storage::shouldReceive('delete')
            ->once()
            ->with('org-7/estimates/42/structure_snapshot.json')
            ->andReturnTrue();

        (new EstimateCacheService())->invalidateStructure($estimate);

        $this->assertNull($estimate->structure_cache_path);
    }
}
