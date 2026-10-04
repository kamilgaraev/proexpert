<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeDocumentRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantNativeDocumentRegistryTest extends TestCase
{
    public function test_only_declared_source_marker_and_parent_pair_selects_native_adapter(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $sales = (new ReflectionClass(AssistantSalesNativeFileAdapter::class))->newInstanceWithoutConstructor();
        $operations = (new ReflectionClass(AssistantOperationsNativeFileAdapter::class))->newInstanceWithoutConstructor();
        $container->instance(AssistantSalesNativeFileAdapter::class, $sales);
        $container->instance(AssistantOperationsNativeFileAdapter::class, $operations);
        try {
            $document = new AIAssistantDocument;
            $document->setRawAttributes(['file_id' => null, 'parent_entity_type' => 'warehouse_item_gallery',
                'parent_entity_id' => '7', 'metadata' => json_encode(['assistant_native_source' => 'operations_native', 'native_source_id' => '91'], JSON_THROW_ON_ERROR)]);
            self::assertSame($operations, AssistantNativeDocumentRegistry::forDocument($document));
            self::assertSame('7', $document->parent_entity_id);
            self::assertSame('91', $document->metadata['native_source_id']);
            $document->setRawAttributes(['file_id' => null, 'parent_entity_type' => 'warehouse_item_gallery',
                'metadata' => json_encode(['assistant_native_source' => 'sales_native'], JSON_THROW_ON_ERROR)]);
            self::assertNull(AssistantNativeDocumentRegistry::forDocument($document));
            $document->setRawAttributes(['file_id' => null, 'parent_entity_type' => 'commercial_proposal_file',
                'metadata' => json_encode(['assistant_native_source' => 'sales_native'], JSON_THROW_ON_ERROR)]);
            self::assertSame($sales, AssistantNativeDocumentRegistry::forDocument($document));
            $document->file_id = 2;
            self::assertNull(AssistantNativeDocumentRegistry::forDocument($document));
            self::assertNull(AssistantNativeDocumentRegistry::adapter('client_supplied_native_source'));
        } finally { Container::setInstance($previous); }
    }

    public function test_default_schedule_gate_uses_actual_canonical_view_permission(): void
    {
        $schedule = array_values(array_filter(AssistantDomainCatalog::defaults(), static fn ($definition): bool => $definition->domain === 'schedule'))[0];
        self::assertSame(['schedule.view'], $schedule->permissions);
        $gates = AssistantEntityAccessCatalog::domainGates();
        self::assertSame(['schedule-management', ['schedule.view']], $gates['schedule']);
    }
}
