<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\Models\ImportSession;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class ImportProgressSyncTest extends TestCase
{
    public function test_processing_progress_uses_cache_but_terminal_progress_uses_persisted_state(): void
    {
        foreach (['processing' => 45, 'completed' => 100, 'validated' => 100, 'failed' => 10] as $status => $expected) {
            $session = new ImportSession(['status' => $status, 'stats' => ['progress' => in_array($status, ['completed', 'validated'], true) ? 100 : 10]]);
            $session->id = '11111111-1111-4111-8111-111111111111';
            $session->created_at = now();
            $service = Mockery::mock(EstimateImportService::class)->makePartial();
            $service->shouldReceive('getSession')->once()->with($session->id, null)->andReturn($session);
            Cache::put("import_session_progress_{$session->id}", 45, 60);
            $result = $service->getImportStatus($session->id);
            self::assertSame($expected, $result['progress']);
            self::assertSame($status, $result['status']);
        }
    }
}
