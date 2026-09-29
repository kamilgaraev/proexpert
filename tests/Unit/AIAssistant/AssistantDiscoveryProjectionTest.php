<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantDiscoveryProjectionTest extends TestCase
{
    public function test_discovery_lists_only_registered_projection_names_and_bounded_read_arguments(): void
    {
        $tool = (new ReflectionClass(DiscoverAssistantDomainCapabilitiesTool::class))->newInstanceWithoutConstructor();
        $definition = new AssistantDomainDefinition('procurement_business', 'procurement', 'procurement_award_evidence_event', [], ['id'], [], ['read'], '');
        $row = (new ReflectionMethod($tool, 'row'))->invoke($tool, $definition, $definition->entityType, ['field_offset' => 0, 'field_limit' => 16], static fn (array $permissions): bool => true);
        $this->assertSame(['award_candidates', 'award_policy'], array_column($row['projections'], 'projection_name'));
        foreach ($row['projections'] as $projection) {
            $this->assertSame('live_read', $projection['status']);
            $this->assertSame('entity_id', $projection['parent_identifier']);
            $this->assertSame(['offset_argument' => 'projection_offset', 'limit_argument' => 'projection_limit', 'max_limit' => 20], $projection['pagination']);
        }
        $json = json_encode($row, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('policy_hash', $json);
        $this->assertStringNotContainsString('procurement_award_policy_versions', $json);
        $this->assertStringNotContainsString('supplier_party_id', $json);
        $unknown = (new ReflectionMethod($tool, 'row'))->invoke($tool, $definition, 'client_sql_projection', ['field_offset' => 0, 'field_limit' => 16], static fn (array $permissions): bool => true);
        $this->assertSame([], $unknown['projections']);
    }
}
