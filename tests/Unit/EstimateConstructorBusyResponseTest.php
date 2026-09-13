<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Api\Estimates\EstimateConstructorController;
use App\Http\Requests\Api\Estimates\Constructor\BulkUpdateItemsRequest;
use App\Models\User;
use App\Services\Estimates\EstimateConstructorService;
use Illuminate\Database\QueryException;
use Mockery;
use Tests\TestCase;

final class EstimateConstructorBusyResponseTest extends TestCase
{
    public function test_busy_estimate_returns_safe_conflict_instead_of_server_error(): void
    {
        $previous = new class extends \PDOException {
            public function __construct()
            {
                parent::__construct('private query details');
                $this->code = '55P03';
            }
        };
        $exception = new QueryException('pgsql', 'private query', [], $previous);
        $service = Mockery::mock(EstimateConstructorService::class);
        $service->shouldReceive('bulkUpdate')->once()->andThrow($exception);
        $request = Mockery::mock(BulkUpdateItemsRequest::class)->makePartial();
        $request->initialize();
        $request->shouldReceive('validated')->andReturn(['items' => [['id' => 1, 'quantity' => 2]]]);
        $actor = new User(['current_organization_id' => 38]);
        $request->setUserResolver(static fn (): User => $actor);
        $response = (new EstimateConstructorController($service))->bulkUpdate($request, 428);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(trans_message('estimate_constructor.busy'), $response->getData(true)['message']);
        $this->assertStringNotContainsString('private', $response->getContent());
    }
}
