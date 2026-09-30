<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection;
use PHPUnit\Framework\TestCase;

final class AssistantToolResultProjectionTest extends TestCase
{
    public function test_only_equal_reference_context_is_shared_without_losing_distinct_scope(): void
    {
        $references = [
            ['entity_type' => 'estimate_item', 'entity_id' => 10, 'organization_id' => 15, 'estimate_id' => 41,
                'fetched_at' => '2026-10-01T01:00:00Z', 'navigation' => ['url' => '/estimates/41']],
            ['entity_type' => 'estimate_item', 'entity_id' => 11, 'organization_id' => 15, 'estimate_id' => 42,
                'fetched_at' => '2026-10-01T01:00:01Z', 'navigation' => ['url' => '/estimates/42']],
        ];
        $result = ['status' => 'success', 'source_refs' => $references];

        $view = AssistantToolResultProjection::forProvider('assistant_domain_read', $result);

        self::assertSame(['organization_id' => 15], $view['source_context']);
        foreach ($references as $index => $reference) {
            self::assertEquals($reference, $view['source_refs'][$index] + $view['source_context']);
            self::assertSame($reference['estimate_id'], $view['source_refs'][$index]['estimate_id']);
            self::assertSame($reference['fetched_at'], $view['source_refs'][$index]['fetched_at']);
        }
        self::assertSame($references, $result['source_refs']);
    }

    public function test_nonduplicated_fields_windows_notices_and_minimal_references_are_preserved(): void
    {
        $reference = ['entity_type' => 'estimate_item_resource', 'entity_id' => 91, 'estimate_id' => 42,
            'navigation' => ['url' => '/estimates/42?position_id=12'], 'checked_fields' => ['quantity', 'unit'],
            'required_permissions' => ['budget-estimates.view'], 'version' => str_repeat('a', 64)];
        $result = ['status' => 'partial', 'needs_clarification' => true,
            'results' => [['entity_type' => 'estimate_item_resource', 'id' => 91,
                'fields' => ['quantity' => '12.5000', 'currency' => 'RUB', 'version' => '2', 'unit' => 'м³']]],
            'source_refs' => [$reference], 'result_window' => ['limit' => 5, 'returned' => 5, 'has_more' => true],
            'retrieval_coverage' => ['mode' => 'returned_records'], 'server_formatted_answer' => 'Особые ограничения области.',
            'server_formatted_facts' => 'Дополнительная информация о недоступном приложении.',
            'structured_fact_evidence' => ['version' => str_repeat('b', 64), 'scope' => 'returned_entity_fields',
                'rows' => [['entity_type' => 'estimate_item_resource', 'entity_id' => 91,
                    'fields' => ['quantity' => '12.5000', 'resource_type' => 'material'], 'source_ref' => $reference]]]];

        $view = AssistantToolResultProjection::forProvider('assistant_domain_read', $result);

        self::assertSame($result['results'], $view['results']);
        self::assertSame($result['result_window'], $view['result_window']);
        self::assertSame($result['retrieval_coverage'], $view['retrieval_coverage']);
        self::assertSame($result['server_formatted_answer'], $view['server_formatted_answer']);
        self::assertSame($result['server_formatted_facts'], $view['server_formatted_facts']);
        self::assertTrue($view['needs_clarification']);
        self::assertSame('material', $view['structured_fact_evidence']['rows'][0]['fields']['resource_type']);
        self::assertSame(42, $view['source_refs'][0]['estimate_id']);
        self::assertSame($reference['navigation'], $view['source_refs'][0]['navigation']);
        self::assertArrayNotHasKey('version', $view['structured_fact_evidence']);
        self::assertArrayNotHasKey('checked_fields', $view['source_refs'][0]);
        self::assertArrayNotHasKey('required_permissions', $view['source_refs'][0]);
        self::assertSame(str_repeat('b', 64), $result['structured_fact_evidence']['version']);
    }
}
