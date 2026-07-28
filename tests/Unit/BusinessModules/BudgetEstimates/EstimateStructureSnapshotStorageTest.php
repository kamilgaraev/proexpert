<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateStructureSnapshotStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EstimateStructureSnapshotStorageTest extends TestCase
{
    public function test_it_reads_snapshot_from_s3_contract_not_local_storage(): void
    {
        Storage::fake('s3');
        Storage::fake('local');

        $path = 'org-7/estimates/42/structure_snapshot.json';
        Storage::disk('s3')->put($path, '{"sections":[{"id":1}]}');
        Storage::disk('local')->put($path, '{"sections":[{"id":999}]}');

        $storage = new EstimateStructureSnapshotStorage();

        $this->assertTrue($storage->exists($path));

        $stream = $storage->readStream($path);
        $contents = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame('{"sections":[{"id":1}]}', $contents);
    }

    public function test_delete_uses_s3_contract_only(): void
    {
        Storage::fake('s3');
        Storage::fake('local');

        $path = 'org-7/estimates/42/structure_snapshot.json';
        Storage::disk('s3')->put($path, '{"sections":[]}');
        Storage::disk('local')->put($path, '{"sections":[]}');

        (new EstimateStructureSnapshotStorage())->delete($path);

        Storage::disk('s3')->assertMissing($path);
        Storage::disk('local')->assertExists($path);
    }
}
