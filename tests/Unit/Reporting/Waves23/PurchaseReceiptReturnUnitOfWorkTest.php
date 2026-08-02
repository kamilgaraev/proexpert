<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Services\DatabasePurchaseReceiptReturnUnitOfWork;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PurchaseReceiptReturnUnitOfWorkTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_operation_is_serialized_by_exact_organization_and_idempotency_key(): void
    {
        $database = $this->databaseFacade();
        $database->expects('transaction')
            ->once()
            ->with(Mockery::type(\Closure::class), 3)
            ->andReturnUsing(static fn (\Closure $operation): mixed => $operation());
        $database->expects('selectOne')
            ->once()
            ->with(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, ?))',
                ['purchase-receipt-return:return-key-0001', 19],
            );

        $result = (new DatabasePurchaseReceiptReturnUnitOfWork)->run(
            19,
            'return-key-0001',
            static fn (): string => 'committed',
        );

        self::assertSame('committed', $result);
    }

    public function test_operation_exception_escapes_transaction_so_database_rolls_back(): void
    {
        $database = $this->databaseFacade();
        $database->expects('transaction')
            ->once()
            ->with(Mockery::type(\Closure::class), 3)
            ->andReturnUsing(static fn (\Closure $operation): mixed => $operation());
        $database->shouldReceive('selectOne')->withAnyArgs()->once();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inventory write failed');

        (new DatabasePurchaseReceiptReturnUnitOfWork)->run(
            19,
            'return-key-0002',
            static function (): never {
                throw new RuntimeException('inventory write failed');
            },
        );
    }

    private function databaseFacade(): DatabaseManager
    {
        $container = new Container;
        $database = Mockery::mock(DatabaseManager::class);
        $container->instance('db', $database);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('db');

        return $database;
    }
}
