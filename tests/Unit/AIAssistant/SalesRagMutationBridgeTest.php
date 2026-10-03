<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\SalesRagMutationBridge;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\Crm\Models\CrmContactPoint;
use App\Models\Contract;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class SalesRagMutationBridgeTest extends TestCase
{
    public function test_identity_is_limited_to_current_indexed_sales_entities(): void
    {
        foreach (AssistantSalesBusinessMetadata::recordDefinitions() as $type => $record) {
            $mode = AssistantSalesBusinessMetadata::retrievalCoverageDefinitions()[$type]['mode'];
            $expected = in_array($mode, ['live_only', 'unavailable'], true) ? null : \App\BusinessModules\Features\AIAssistant\Services\Rag\CoreRagMutationBridge::definition($record['model']);
            self::assertSame($expected, SalesRagMutationBridge::definition($record['model']));
        }
        self::assertNull(SalesRagMutationBridge::definition(Contract::class));
        self::assertNull(SalesRagMutationBridge::definition('App\\Models\\Unknown'));
    }

    public function test_unknown_model_never_reads_rows_or_resolves_the_container(): void
    {
        $rows = $this->createMock(Builder::class);
        $rows->expects(self::never())->method('getModel');
        SalesRagMutationBridge::changedRows(Contract::class, $rows, 1);
        SalesRagMutationBridge::queue(Contract::class, 1, null, 9);
        self::assertNull(SalesRagMutationBridge::definition(Contract::class));
    }

    public function test_pause_blocks_coordinator_dispatch_and_row_loading(): void
    {
        $oldContainer = Container::getInstance();
        $oldFacade = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $state = new AssistantIndexingState();
        $state->beginMigration();
        $container->instance(AssistantIndexingState::class, $state);
        $coordinator = $this->createMock(RagIndexingCoordinator::class);
        $coordinator->expects(self::never())->method('queueEntity');
        $container->instance(RagIndexingCoordinator::class, $coordinator);
        DB::swap(new SalesMutationPureTransactions());
        try {
            $query = new \Illuminate\Database\Query\Builder($this->createMock(\Illuminate\Database\Connection::class));
            $rows = new Builder($query);
            $rows->setModel(new CrmContactPoint());
            SalesRagMutationBridge::changedRows(CrmContactPoint::class, $rows, 1);
            SalesRagMutationBridge::queue(CrmContactPoint::class, 1, null, 'native-uuid');
            self::assertTrue($state->paused());
        } finally {
            DB::clearResolvedInstance('db');
            Facade::setFacadeApplication($oldFacade);
            Container::setInstance($oldContainer);
        }
    }
}

final class SalesMutationPureTransactions
{
    public function transactionLevel(): int { return 0; }

    public function transaction(callable $operation): mixed { return $operation(); }
}
