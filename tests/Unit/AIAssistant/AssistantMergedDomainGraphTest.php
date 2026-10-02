<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantMergedDomainGraphTest extends TestCase
{
    public function test_complete_declared_parent_graph_has_no_unprotected_cycles_or_unknown_parents(): void
    {
        $helpers = [DomainMetadata\AssistantFinanceTenderMetadata::class, DomainMetadata\AssistantWorkforceCatalogMetadata::class,
            DomainMetadata\VideoMonitoringAssistantMetadata::class, DomainMetadata\AssistantDesignAdditionalMetadata::class,
            DomainMetadata\AssistantSalesBusinessMetadata::class, DomainMetadata\AssistantOperationsBusinessMetadata::class,
            DomainMetadata\AssistantLegalBusinessMetadata::class, DomainMetadata\AssistantOrganizationReportingMetadata::class,
            DomainMetadata\AssistantCoreBusinessMetadata::class];
        $policy = (new ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();
        $entities = (new ReflectionMethod(AssistantDataAccessPolicy::class, 'entities'))->invoke($policy);
        $parents = (new ReflectionClass(AssistantDataAccessPolicy::class))->getConstant('SECURITY_PARENT_COLUMNS');
        $owners = [];
        foreach ($helpers as $helper) {
            foreach ($helper::entityDefinitions() as $type => $definition) {
                self::assertArrayNotHasKey($type, $owners, 'Duplicate metadata owner: '.$type);
                $owners[$type] = $helper;
                $entities[$type] = $definition;
            }
            $parents = array_replace($parents, $helper::parentColumns());
        }
        foreach ((new ReflectionClass(AssistantDataAccessPolicy::class))->getConstant('PARENTS') as $type => $definition) {
            $parents[$type]['intrinsic_parent'] = ['type' => $definition[1]];
        }
        $unknown = [];
        foreach ($parents as $type => $edges) {
            foreach ($edges as $column => $parent) {
                if (! isset($entities[$parent['type']])) { $unknown[] = $type.'.'.$column.' -> '.$parent['type']; }
            }
        }
        self::assertSame([], $unknown, implode(', ', $unknown));
        $seen = [];
        $visit = function (string $type, array $path) use (&$visit, &$seen, $parents, $entities): void {
            self::assertArrayHasKey($type, $entities, 'Unknown security parent '.$type);
            self::assertNotContains($type, $path, implode(' -> ', [...$path, $type]));
            if (isset($seen[$type])) { return; }
            foreach ($parents[$type] ?? [] as $column => $parent) {
                if (($parent['reference_only'] ?? false) === true) {
                    self::assertSame($type, $parent['type'], 'Nonrecursive reference must be intrinsic: '.$type.'.'.$column);
                    continue;
                }
                $visit($parent['type'], [...$path, $type]);
            }
            $seen[$type] = true;
        };
        foreach (array_keys($entities) as $type) { $visit($type, []); }
        self::assertGreaterThan(450, count($owners));
    }
}
