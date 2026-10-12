<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantToolResultProjection;
use App\BusinessModules\Features\AIAssistant\Services\AssistantPresentationPlanner;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantToolResultProjectionTest extends TestCase
{
    use UsesAssistantUnitTranslations;

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

    public function test_contract_snapshot_nested_current_contract_and_party_advertise_stable_presentation_sets_without_private_proof(): void
    {
        $contract = $this->liveRead('contract', 10, ['number' => 'ТЕСТ', 'subject' => 'Текущий предмет',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_amount' => '1234.50', 'contractor_id' => 20]);
        $party = $this->liveRead('contractor', 20, ['id' => 20, 'name' => 'Текущий подрядчик']);
        $snapshot = ['status' => 'success', 'contracts' => [['contract' => ['id' => 10], 'contractor' => ['id' => 20]]],
            'live_entity_reads' => [$contract, $party]];
        $view = AssistantToolResultProjection::forProvider('get_contract_snapshot', $snapshot);

        self::assertCount(2, $view['live_entity_reads']);
        foreach ([$contract, $party] as $index => $read) {
            $nested = $view['live_entity_reads'][$index];
            self::assertSame(AssistantPresentationPlanner::providerView($read), $nested['presentation_contract']);
            self::assertSame('r01', $nested['results'][0]['presentation_ref']);
            self::assertSame($read['results'][0]['fields'], $nested['results'][0]['fields']);
            self::assertArrayNotHasKey('rows', $nested['structured_fact_evidence']);
            self::assertArrayNotHasKey('source_field_identity', $nested['source_refs'][0]);
        }
        self::assertContains('currency', $view['live_entity_reads'][0]['presentation_contract']['fields']);
        self::assertSame(20, $view['live_entity_reads'][0]['results'][0]['fields']['contractor_id']);
        self::assertContains('name', $view['live_entity_reads'][1]['presentation_contract']['fields']);
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);
        foreach (['private-proof-marker', 'checked_fields', 'required_permissions', 'source_field_identity', '_search_count_proof'] as $private) {
            self::assertStringNotContainsString($private, $encoded);
        }
        self::assertSame('private-proof-marker', $snapshot['live_entity_reads'][0]['source_refs'][0]['source_field_identity']);
    }

    public function test_nested_live_reads_are_bounded_and_invalid_or_recursive_payload_is_not_exposed(): void
    {
        $read = $this->liveRead('contract', 10, ['number' => 'ТЕСТ']);
        $read['live_entity_reads'] = [['private-proof-marker' => 'private-proof-marker']];
        $invalid = ['results' => [], 'source_refs' => [], 'private_payload' => 'private-proof-marker'];
        $view = AssistantToolResultProjection::forProvider('get_contract_snapshot', ['live_entity_reads' =>
            array_merge([$invalid], array_fill(0, 30, $read))]);

        self::assertCount(AssistantStructuredFactFormatter::MAX_ROWS - 1, $view['live_entity_reads']);
        self::assertStringNotContainsString('private-proof-marker', json_encode($view, JSON_THROW_ON_ERROR));
        foreach ($view['live_entity_reads'] as $nested) { self::assertArrayNotHasKey('live_entity_reads', $nested); }
    }

    public function test_bim_provider_preserves_full_counts_without_repeating_all_examples_and_private_receipts(): void
    {
        $element = ['id' => 1, 'express_id' => 100, 'name' => 'Установка', 'category' => 'IFCBUILDINGELEMENTPROXY',
            'equipment_kind' => 'air_conditioner', 'properties' => array_fill(0, 100, str_repeat('x', 255)),
            '_source_field_identity' => 'private-marker'];
        $model = ['version' => ['id' => 15, 'project_id' => 52, 'title' => 'АС', 'source_file_path' => 'private-marker'],
            'semantic_matching_count' => 588, 'total' => 588, 'scan_complete' => true, 'has_more' => true, 'next_offset' => 20,
            'index_coverage' => ['status' => 'complete'], 'semantic_coverage' => ['status' => 'complete'],
            'elements' => array_fill(0, 20, $element), '_fingerprint' => 'private-marker'];
        $result = ['status' => 'success', 'validation_status' => 'verified', 'server_summary_answer' => 'Всего: 836.',
            'server_formatted_answer' => str_repeat('Подробности ', 4000), 'models' => [$model],
            'bim_evidence' => ['filters' => ['include_properties' => false, 'equipment_kind' => 'air_conditioner'], 'version' => 'private-marker'],
            'source_refs' => [['source_version' => 'private-marker']]];
        $view = AssistantToolResultProjection::forProvider('get_bim_model_elements', $result);
        self::assertSame('Всего: 836.', $view['server_formatted_answer']);
        self::assertSame(588, $view['models'][0]['semantic_matching_count']);
        self::assertSame(20, $view['models'][0]['next_offset']);
        self::assertSame(20, $view['models'][0]['available_page_elements']);
        self::assertTrue($view['models'][0]['examples_truncated']);
        self::assertCount(1, $view['models'][0]['elements']);
        self::assertStringNotContainsString('private-marker', json_encode($view, JSON_THROW_ON_ERROR));
        self::assertLessThan(4096, strlen(json_encode($view, JSON_THROW_ON_ERROR)));
        self::assertCount(20, $result['models'][0]['elements']);
        $result['bim_evidence']['filters']['include_properties'] = true;
        self::assertCount(8, AssistantToolResultProjection::forProvider('get_bim_model_elements', $result)['models'][0]['elements'][0]['properties']);
    }

    public function test_bim_projection_preserves_requested_properties_links_and_property_changes(): void
    {
        $result = ['status' => 'success', 'bim_evidence' => ['filters' => ['property_name' => 'CoolingCapacity',
            'include_properties' => false, 'include_estimate_links' => true]], 'models' => [[
                'elements' => [['id' => 1, 'classifications' => ['AIRHANDLER'], 'properties' => ['CoolingCapacity' => 330],
                    'estimate_links' => [['estimate_id' => 5, 'estimate_item_id' => 6, 'name' => 'Монтаж', '_source_field_identity' => 'private-marker']]]],
                'estimate_link_coverage' => 'page_limited']], 'comparison' => ['status' => 'complete', 'changes' => [[
                    'kind' => 'changed', 'global_id' => 'ahu', 'before' => ['name' => 'AHU', 'properties' => ['CoolingCapacity' => 330]],
                    'after' => ['name' => 'AHU', 'properties' => ['CoolingCapacity' => 400]], '_source_field_identity' => 'private-marker']]]];
        $view = AssistantToolResultProjection::forProvider('get_bim_model_elements', $result);
        self::assertSame(330, $view['models'][0]['elements'][0]['properties']['CoolingCapacity']);
        self::assertSame(5, $view['models'][0]['elements'][0]['estimate_links'][0]['estimate_id']);
        self::assertSame('page_limited', $view['models'][0]['estimate_link_coverage']);
        self::assertSame(330, $view['comparison']['changes'][0]['before']['properties']['CoolingCapacity']);
        self::assertSame(400, $view['comparison']['changes'][0]['after']['properties']['CoolingCapacity']);
        self::assertSame(['AIRHANDLER'], $view['models'][0]['elements'][0]['classifications']);
        self::assertStringNotContainsString('private-marker', json_encode($view, JSON_THROW_ON_ERROR));
    }

    private function liveRead(string $type, int $id, array $fields): array
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => $id] + $fields, true);
        $reference = ['entity_type' => $type, 'entity_id' => $id, 'organization_id' => 15, 'content_scope' => 'structured',
            'checked_fields' => array_keys($fields), 'source_version' => 'private-proof-marker', 'source_field_identity' => 'private-proof-marker',
            'required_permissions' => ['contractors.view'], 'fetched_at' => '2026-10-12T08:00:00Z',
            '_search_count_proof' => 'private-proof-marker'];
        $row = AssistantStructuredFactFormatter::row($model, $type, array_keys($fields), $reference);

        return ['results' => [['entity_type' => $type, 'id' => $id, 'fields' => $fields]], 'source_refs' => [$reference]]
            + AssistantStructuredFactFormatter::payload([$row], $reference['fetched_at']);
    }
}
