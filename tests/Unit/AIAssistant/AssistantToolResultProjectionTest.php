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

        self::assertSame(['entity_type' => 'estimate_item', 'organization_id' => 15], $view['source_context']);
        foreach ($references as $index => $reference) {
            self::assertEquals($reference, $view['source_refs'][$index] + $view['source_context']);
            self::assertSame($reference['estimate_id'], $view['source_refs'][$index]['estimate_id']);
            self::assertSame($reference['fetched_at'], $view['source_refs'][$index]['fetched_at']);
        }
        self::assertSame($references, $result['source_refs']);
    }

    public function test_estimate_search_preserves_matches_and_continuation_without_repeating_server_evidence(): void
    {
        $matches = [
            ['estimate' => ['id' => 41, 'number' => 'СМ-41', 'name' => 'Корпус', 'project_id' => 5],
                'position' => ['id' => 10, 'estimate_id' => 41, 'name' => 'Бетон', 'position_number' => '1', 'version' => str_repeat('a', 64)]],
            ['estimate' => ['id' => 42, 'number' => 'СМ-42', 'name' => 'Гараж', 'project_id' => 6],
                'position' => ['id' => 11, 'estimate_id' => 42, 'name' => 'Бетон', 'position_number' => '2', 'version' => str_repeat('b', 64)]],
        ];
        $result = ['status' => 'success', 'search_complete' => false, 'has_more' => true, 'next_cursor' => 'opaque-cursor',
            'meta' => ['returned' => 2, 'per_page' => 12], 'matches' => $matches,
            'structured_fact_evidence' => ['rows' => [['fields' => ['name' => 'Бетон']]], 'version' => 'server-version'],
            'source_refs' => [['organization_id' => 15, 'estimate_id' => 41]], 'server_formatted_facts' => 'Проверенные позиции'];

        $view = AssistantToolResultProjection::forProvider('search_estimate_positions', $result);

        self::assertSame('opaque-cursor', $view['next_cursor']);
        self::assertFalse($view['search_complete']);
        self::assertTrue($view['has_more']);
        foreach ($matches as $index => $match) {
            self::assertSame($match['estimate'], $view['matches'][$index]['estimate']);
            self::assertSame(array_diff_key($match['position'], ['version' => true]), $view['matches'][$index]['position']);
        }
        self::assertArrayNotHasKey('source_refs', $view);
        self::assertArrayNotHasKey('structured_fact_evidence', $view);
        self::assertArrayNotHasKey('server_formatted_facts', $view);
        self::assertSame('server-version', $result['structured_fact_evidence']['version']);
    }

    public function test_only_navigation_already_present_on_the_same_primary_record_is_deduplicated(): void
    {
        $navigation = ['url' => '/estimates/41?position_id=10'];
        $result = ['results' => [['entity_type' => 'estimate_item', 'id' => 10,
            'fields' => ['name' => 'Бетон'], 'navigation' => $navigation]],
            'source_refs' => [['entity_type' => 'estimate_item', 'entity_id' => 10, 'navigation' => $navigation],
                ['entity_type' => 'estimate_item', 'entity_id' => 11, 'navigation' => $navigation]]];

        $view = AssistantToolResultProjection::forProvider('assistant_domain_read', $result);

        self::assertArrayNotHasKey('navigation', $view['source_refs'][0]);
        self::assertSame($navigation, $view['source_refs'][1]['navigation']);
        self::assertSame($result['results'], $view['results']);
        self::assertSame($navigation, $result['source_refs'][0]['navigation']);
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
