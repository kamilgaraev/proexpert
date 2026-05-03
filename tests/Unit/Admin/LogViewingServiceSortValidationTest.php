<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use App\Services\Admin\LogViewingService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class LogViewingServiceSortValidationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function refreshTestDatabase(): void
    {
    }

    public function test_work_completion_logs_fall_back_to_safe_sorting(): void
    {
        $request = Request::create('/logs', 'GET', [
            'sort_by' => 'id desc',
            'sort_direction' => 'sideways',
        ]);
        $request->attributes->set('current_organization_id', 10);

        $paginator = new LengthAwarePaginator([], 0, 15);
        $repository = Mockery::mock(WorkCompletionLogRepositoryInterface::class);
        $repository
            ->shouldReceive('getPaginatedLogs')
            ->once()
            ->with(10, 15, [], 'created_at', 'desc')
            ->andReturn($paginator);

        $service = new LogViewingService($repository);

        $this->assertSame($paginator, $service->getWorkCompletionLogs($request));
    }
}
