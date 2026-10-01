<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\Rag\DesignRagMutationBridge;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcUploadSession;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DesignRagMutationBridgeTest extends TestCase
{
    public function test_only_actual_registered_design_models_have_exact_business_identities(): void
    {
        foreach ([DesignCompositionRevision::class => 'design_composition_revision', DesignDocumentSheet::class => 'design_document_sheet', DesignIfcModelElement::class => 'design_ifc_model_element', DesignIfcUploadSession::class => 'design_ifc_upload_session'] as $model => $type) {
            self::assertSame(['design_additional', $type], DesignRagMutationBridge::definition($model));
        }
        self::assertSame(['design', 'design_package'], DesignRagMutationBridge::definition(DesignPackage::class));
        foreach ([self::class, \stdClass::class, 'design_ifc_model_elements', DesignIfcModelElement::class.'; DROP TABLE design_packages'] as $untrusted) {
            self::assertNull(DesignRagMutationBridge::definition($untrusted));
        }
    }

    public function test_migration_pause_and_oversized_ifc_batches_skip_native_queries_and_dispatch(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacades = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $state = new AssistantIndexingState;
        $state->beginMigration();
        $container->instance(AssistantIndexingState::class, $state);
        $coordinator = $this->createMock(RagIndexingCoordinator::class);
        $coordinator->expects(self::never())->method('queueEntity');
        $container->instance(RagIndexingCoordinator::class, $coordinator);
        DB::swap(new DesignMutationPureTransactions);
        try {
            $rows = $this->createMock(Builder::class);
            $rows->expects(self::never())->method('getModel');
            $bridge = new DesignRagMutationBridge;
            $bridge->changedRows(DesignDocumentSheet::class, $rows);
            $bridge->ifcRows([['version_id' => 1, 'express_id' => 77]]);
            $state->endMigration();
            $bridge->ifcRows(array_fill(0, 501, ['version_id' => 1, 'express_id' => 77]));
            self::assertFalse($state->paused());
        } finally {
            DB::clearResolvedInstance('db');
            Facade::setFacadeApplication($previousFacades);
            Container::setInstance($previousContainer);
        }
    }

    public function test_hook_transaction_failure_cannot_escape_into_business_operation(): void
    {
        $previous = Facade::getFacadeApplication();
        Facade::setFacadeApplication(new Container);
        DB::swap(new class {
            public function transactionLevel(): int { return 0; }
            public function transaction(callable $operation): void { throw new RuntimeException('synthetic_queue_failure'); }
        });
        try {
            self::assertNull((new DesignRagMutationBridge)->ifcRows([]));
        } finally {
            DB::clearResolvedInstance('db');
            Facade::setFacadeApplication($previous);
        }
    }
}

final class DesignMutationPureTransactions
{
    public function transactionLevel(): int { return 0; }
    public function transaction(callable $operation): mixed { return $operation(); }
}
