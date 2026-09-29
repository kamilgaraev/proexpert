<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\CoreRagMutationBridge;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Contract;
use App\Models\SupplementaryAgreement;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class CoreRagMutationBridgeTest extends TestCase
{
    public function test_registered_native_and_financial_models_use_exact_current_business_source_identities(): void
    {
        self::assertSame(['core_business_money', 'core_payment_schedule'], CoreRagMutationBridge::definition(PaymentSchedule::class));
        self::assertSame(['core_business_money', 'core_supplementary_agreement'], CoreRagMutationBridge::definition(SupplementaryAgreement::class));
        self::assertSame(['contract', 'contract'], CoreRagMutationBridge::definition(Contract::class));
        self::assertSame(['payment', 'payment_document'], CoreRagMutationBridge::definition(PaymentDocument::class));
        foreach (AssistantFinanceTenderMetadata::observerDefinitions() as $model => $identity) {
            self::assertSame($identity, CoreRagMutationBridge::definition($model), $model);
        }
    }

    public function test_live_only_private_models_and_untrusted_model_strings_have_no_bulk_indexing_identity(): void
    {
        $private = 0;
        foreach (AssistantCoreBusinessMetadata::records() as $record) {
            if ($record['indexed']) { continue; }
            $private++;
            self::assertNull(CoreRagMutationBridge::definition($record['model']), $record['model']);
        }
        self::assertGreaterThan(0, $private);
        foreach ([self::class, \stdClass::class, 'App\\Models\\UnknownEntity', PaymentSchedule::class.'; DELETE FROM payment_schedules'] as $untrusted) {
            self::assertNull(CoreRagMutationBridge::definition($untrusted));
        }
        $identity = CoreRagMutationBridge::definition(PaymentSchedule::class);
        $identity[0] = 'untrusted_source';
        self::assertSame(['core_business_money', 'core_payment_schedule'], CoreRagMutationBridge::definition(PaymentSchedule::class));
    }

    public function test_migration_pause_skips_row_reads_and_queue_dispatch_without_a_database(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacades = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $state = new AssistantIndexingState();
        $state->beginMigration();
        $container->instance(AssistantIndexingState::class, $state);
        $coordinator = $this->createMock(RagIndexingCoordinator::class);
        $coordinator->expects(self::never())->method('queueEntity');
        $container->instance(RagIndexingCoordinator::class, $coordinator);
        DB::swap(new CoreMutationPureTransactions());
        try {
            $rows = $this->createMock(Builder::class);
            $rows->expects(self::never())->method('getModel');
            $bridge = new CoreRagMutationBridge();
            $bridge->changedRows(PaymentSchedule::class, $rows, 1, 2);
            $bridge->queue(PaymentSchedule::class, 1, 2, 3);
            self::assertTrue($state->paused());
        } finally {
            DB::clearResolvedInstance('db');
            Facade::setFacadeApplication($previousFacades);
            Container::setInstance($previousContainer);
        }
    }
}

final class CoreMutationPureTransactions
{
    public function transaction(callable $operation): mixed { return $operation(); }
}
