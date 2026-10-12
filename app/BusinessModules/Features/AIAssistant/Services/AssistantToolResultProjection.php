<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantToolResultProjection
{
    public static function forProvider(string $toolName, array $result): array
    {
        if ($toolName === 'search_estimate_positions') {
            if (($result['price_subject'] ?? null) === 'concrete_m3') {
                $view = array_intersect_key($result, array_flip(['price_subject', 'search_scope', 'search_complete', 'has_more', 'next_cursor', 'validation_status']));
                $view['estimate'] = array_intersect_key($result['estimate'] ?? [], array_flip(['id', 'number', 'name', 'project_id']));
                $view['positions'] = array_map(static fn (array $position): array => array_intersect_key($position,
                    array_flip(['id', 'position_number', 'name', 'unit', 'unit_price', 'current_unit_price', 'currency'])), array_slice($result['positions'] ?? [], 0, 1));
                return $view;
            }
            $view = array_intersect_key($result, array_flip(['status', 'search_status', 'search_scope',
                'search_complete', 'has_more', 'next_cursor', 'meta', 'matches', 'error', 'reason']));
            foreach ($view['matches'] ?? [] as $index => $match) {
                if (is_array($match['position'] ?? null)) {
                    $view['matches'][$index]['position'] = self::position($match['position']);
                }
            }

            return $view;
        }
        if (in_array($toolName, ['get_material_stock', 'get_project_material_stock'], true)) {
            return array_intersect_key($result, array_flip(['status', 'stock', 'quantity_scope', 'server_formatted_answer', 'validation_status', 'error', 'reason', 'total', 'has_more', 'next_offset']));
        }
        if ($toolName === 'get_bim_model_elements') {
            $view = array_intersect_key($result, array_flip(['status', 'needs_clarification', 'validation_status', 'error', 'reason']));
            $view['server_formatted_answer'] = $result['server_summary_answer'] ?? null;
            $filters = $result['bim_evidence']['filters'] ?? [];
            if (($filters['answer_kind'] ?? null) === 'materials') {
                $view['server_formatted_answer'] = trans_message('ai_assistant_bim.provider_material_summary');
            }
            $view['filters'] = array_intersect_key($filters, array_flip(['answer_kind', 'equipment_kind', 'equipment_kinds', 'element_query', 'material_query',
                'classification_query', 'storey_query', 'property_name', 'property_value', 'quantity_name', 'offset', 'limit']));
            $view['models'] = [];
            foreach (array_slice($result['models'] ?? [], 0, 5) as $model) {
                $projected = array_intersect_key($model, array_flip(['source_scope', 'index_coverage', 'semantic_coverage',
                    'semantic_matching_count', 'equipment_counts', 'total', 'has_more', 'next_offset', 'scan_complete', 'estimate_link_coverage', 'material_summary']));
                $projected['version'] = array_intersect_key($model['version'] ?? [], array_flip(['id', 'project_id', 'artifact_id',
                    'title', 'version_number', 'source_original_name', 'is_current']));
                if (($filters['answer_kind'] ?? null) === 'materials') {
                    if (is_array($projected['material_summary'] ?? null)) {
                        $names = $projected['material_summary']['names'] ?? [];
                        $projected['material_summary']['names'] = array_slice($names, 0, 10);
                        $projected['material_summary']['provider_names_truncated'] = count($names) > 10;
                    }
                    $view['models'][] = $projected;
                    continue;
                }
                $projected['categories'] = array_slice($model['categories'] ?? [], 0, 20);
                $projected['categories_truncated'] = count($model['categories'] ?? []) > 20;
                $projected['quantity_totals'] = array_slice($model['quantity_totals'] ?? [], 0, 20);
                $projected['elements'] = [];
                foreach (array_slice($model['elements'] ?? [], 0, 1) as $element) {
                    $example = array_intersect_key($element, array_flip(['id', 'express_id', 'global_id', 'category', 'name',
                        'equipment_kind', 'attributes', 'materials', 'classifications', 'quantities', 'quantity_units']));
                    if (($filters['include_properties'] ?? false) || ! empty($filters['property_name'])) {
                        $properties = $element['properties'] ?? [];
                        $requested = $filters['property_name'] ?? null;
                        if (is_string($requested) && $requested !== '') {
                            $preferred = array_filter($properties, static fn ($key): bool => mb_stripos((string) $key, $requested) !== false, ARRAY_FILTER_USE_KEY);
                            $properties = $preferred + $properties;
                        }
                        $example['properties'] = array_slice($properties, 0, 8, true);
                        $example['properties_truncated'] = count($properties) > 8;
                    }
                    if ($filters['include_estimate_links'] ?? false) {
                        $example['estimate_links'] = [];
                        foreach (array_slice($element['estimate_links'] ?? [], 0, 3) as $link) {
                            $safeLink = array_intersect_key($link, array_flip(['link_id', 'revision', 'source_version_id', 'source_element_id',
                                'status', 'estimate_id', 'estimate_item_id', 'name']));
                            if (is_array($link['target'] ?? null)) {
                                $safeLink['target'] = array_intersect_key($link['target'], array_flip(['entity_type', 'id', 'estimate_id',
                                    'position_number', 'name', 'measurement_unit_id', 'quantity', 'unit_price', 'total_amount', 'currency']));
                            }
                            $example['estimate_links'][] = $safeLink;
                        }
                        $example['estimate_links_truncated'] = count($element['estimate_links'] ?? []) > 3;
                    }
                    $projected['elements'][] = $example;
                }
                $projected['available_page_elements'] = count($model['elements'] ?? []);
                $projected['examples_truncated'] = $projected['available_page_elements'] > count($projected['elements']);
                $view['models'][] = $projected;
            }
            if (is_array($result['comparison'] ?? null)) {
                $view['comparison'] = array_intersect_key($result['comparison'], array_flip(['status', 'counts', 'identity_complete',
                    'has_more', 'before_version_id', 'after_version_id']));
                foreach (array_slice($result['comparison']['changes'] ?? [], 0, 3) as $change) {
                    $projected = array_intersect_key($change, array_flip(['global_id', 'kind']));
                    $beforeProperties = $change['before']['properties'] ?? [];
                    $afterProperties = $change['after']['properties'] ?? [];
                    $changedProperties = array_filter(array_unique([...array_keys($beforeProperties), ...array_keys($afterProperties)]),
                        static fn ($key): bool => ($beforeProperties[$key] ?? null) !== ($afterProperties[$key] ?? null));
                    foreach (['before', 'after'] as $side) {
                        if (is_array($change[$side] ?? null)) {
                            $projected[$side] = array_intersect_key($change[$side], array_flip(['name', 'category', 'attributes',
                                'materials', 'quantities', 'quantity_units', 'classifications']));
                            $projected[$side]['properties'] = array_intersect_key($change[$side]['properties'] ?? [],
                                array_flip(array_slice($changedProperties, 0, 8)));
                        }
                    }
                    $projected['property_changes_truncated'] = count($changedProperties) > 8;
                    $view['comparison']['changes'][] = $projected;
                }
                $view['comparison']['changes_truncated'] = count($result['comparison']['changes'] ?? []) > 3;
            }
            return $view;
        }
        $view = $result;
        if (is_array($result['live_entity_reads'] ?? null)) {
            $view['live_entity_reads'] = [];
            foreach (array_slice($result['live_entity_reads'], 0, AssistantStructuredFactFormatter::MAX_ROWS) as $read) {
                if (! is_array($read) || ! is_array($read['results'] ?? null) || ! is_array($read['source_refs'] ?? null)
                    || AssistantPresentationPlanner::providerView($read) === null) { continue; }
                unset($read['live_entity_reads']);
                $view['live_entity_reads'][] = self::forProvider('assistant_domain_read', $read);
            }
        }
        if (is_array($view['rag_context']['sources'] ?? null)) {
            $view['rag_context']['sources'] = array_map(self::reference(...), $view['rag_context']['sources']);
        }
        if (is_string($view['document_context'] ?? null)) {
            try {
                $documentContext = json_decode($view['document_context'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($documentContext['sources'] ?? null)) {
                    $documentContext['sources'] = array_map(self::reference(...), $documentContext['sources']);
                    $view['document_context'] = json_encode($documentContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            } catch (\JsonException) {
                unset($view['document_context']);
            }
        }
        if (is_array($view['domain_search_count_evidence']['source_ref'] ?? null)) {
            $view['domain_search_count_evidence']['source_ref'] = self::reference($view['domain_search_count_evidence']['source_ref']);
        }
        $primaryRows = self::primaryRows($result);
        foreach (['structured_fact_evidence', 'financial_evidence'] as $key) {
            $evidence = $result[$key] ?? null;
            if (!is_array($evidence)) { continue; }
            if (self::rowsCovered($evidence['rows'] ?? null, $primaryRows)) {
                unset($view[$key]['rows']);
                $formattedKey = $key === 'structured_fact_evidence' ? 'server_formatted_facts' : 'server_formatted_answer';
                if (is_string($evidence['fetched_at'] ?? null)) {
                    $formatted = $key === 'structured_fact_evidence'
                        ? AssistantStructuredFactFormatter::payload($evidence['rows'], $evidence['fetched_at'])
                        : AssistantDomainNumericEvidence::payload($evidence['rows'], $evidence['fetched_at']);
                    if (isset($formatted[$formattedKey]) && ($result[$formattedKey] ?? null) === $formatted[$formattedKey]) {
                        unset($view[$formattedKey]);
                    }
                }
            }
            foreach (['estimate', 'positions', 'selection', 'source_refs'] as $duplicate) {
                if (isset($result[$duplicate], $evidence[$duplicate]) && $result[$duplicate] === $evidence[$duplicate]) {
                    unset($view[$key][$duplicate]);
                }
            }
            $view[$key] = self::evidence($view[$key]);
        }
        if (is_array($view['source_refs'] ?? null)) {
            $view['source_refs'] = array_map(self::reference(...), $view['source_refs']);
            $view = self::sharedSourceContext($view);
            foreach ($view['source_refs'] as $index => $reference) {
                if (! is_array($reference)) {
                    continue;
                }
                $identity = $reference + ($view['source_context'] ?? []);
                $primary = $primaryRows[self::identity($identity['entity_type'] ?? '', $identity['entity_id'] ?? '')] ?? [];
                if (isset($reference['navigation'], $primary['navigation']) && $reference['navigation'] === $primary['navigation']) {
                    unset($view['source_refs'][$index]['navigation']);
                }
            }
        }
        if (is_array($view['positions'] ?? null)) {
            $view['positions'] = array_map(self::position(...), $view['positions']);
        }
        $contract = AssistantPresentationPlanner::providerView($result);
        if ($contract !== null) {
            $view['presentation_contract'] = $contract;
            $refs = [];
            foreach ($result['structured_fact_evidence']['rows'] as $index => $row) {
                $refs[self::identity($row['entity_type'], $row['entity_id'])] = 'r'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            }
            foreach (is_array($view['results'] ?? null) ? $view['results'] : [] as $index => $row) {
                if (!is_array($row)) { continue; }
                $ref = $refs[self::identity($row['entity_type'] ?? '', $row['id'] ?? $row['entity_id'] ?? '')] ?? null;
                if ($ref !== null) { $view['results'][$index]['presentation_ref'] = $ref; }
            }
            foreach (['positions', 'estimate'] as $key) {
                if (isset($view[$key])) { $view[$key] = self::presentationReferences($view[$key], $key, $refs); }
                if (isset($view['financial_evidence'][$key])) {
                    $view['financial_evidence'][$key] = self::presentationReferences($view['financial_evidence'][$key], $key, $refs);
                }
            }
            foreach ($view['structured_fact_evidence']['rows'] ?? [] as $index => $row) {
                $view['structured_fact_evidence']['rows'][$index]['presentation_ref'] = 'r'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            }
        }
        return $view;
    }

    private static function presentationReferences(mixed $rows, string $key, array $refs): mixed
    {
        if (!is_array($rows)) { return $rows; }
        if ($key === 'estimate') {
            $ref = $refs[self::identity('estimate', $rows['id'] ?? '')] ?? null;
            if ($ref !== null) { $rows['presentation_ref'] = $ref; }
            return $rows;
        }
        foreach ($rows as $index => $row) {
            if (!is_array($row)) { continue; }
            $ref = $refs[self::identity('estimate_item', $row['id'] ?? '')] ?? null;
            if ($ref !== null) { $rows[$index]['presentation_ref'] = $ref; }
        }
        return $rows;
    }

    private static function primaryRows(array $result): array
    {
        $rows = [];
        foreach (is_array($result['results'] ?? null) ? $result['results'] : [] as $row) {
            if (is_array($row) && is_array($row['fields'] ?? null)) {
                $rows[self::identity($row['entity_type'] ?? '', $row['id'] ?? $row['entity_id'] ?? '')] = $row['fields']
                    + (is_array($row['navigation'] ?? null) ? ['navigation' => $row['navigation']] : []);
            }
        }
        foreach ([$result, $result['financial_evidence'] ?? []] as $collection) {
            if (!is_array($collection)) { continue; }
            if (is_array($collection['estimate'] ?? null)) {
                $rows[self::identity('estimate', $collection['estimate']['id'] ?? '')] = $collection['estimate'];
            }
            foreach (is_array($collection['positions'] ?? null) ? $collection['positions'] : [] as $position) {
                if (is_array($position)) {
                    $rows[self::identity('estimate_item', $position['id'] ?? '')] = $position;
                }
            }
        }
        return $rows;
    }

    private static function rowsCovered(mixed $rows, array $primary): bool
    {
        if (!is_array($rows) || $rows === []) { return false; }
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['fields'] ?? null) || $row['fields'] === []) { return false; }
            $fields = $primary[self::identity($row['entity_type'] ?? '', $row['entity_id'] ?? '')] ?? [];
            foreach ($row['fields'] as $field => $value) {
                if (!array_key_exists($field, $fields) || $fields[$field] !== $value) { return false; }
            }
            if (is_array($row['parent_ids'] ?? null)) {
                foreach ($row['parent_ids'] as $field => $value) {
                    if (!array_key_exists($field, $fields) || $fields[$field] !== $value) { return false; }
                }
            }
        }
        return true;
    }

    private static function identity(mixed $type, mixed $id): string
    {
        return json_encode([(string) $type, (string) $id], JSON_THROW_ON_ERROR);
    }

    private static function reference(mixed $reference): mixed
    {
        if (!is_array($reference)) { return $reference; }
        return array_diff_key($reference, array_flip(['version', 'source_version', 'checksum', 'source_field_identity', 'source_link_identity', 'checked_fields',
            'required_permissions', 'required_domains', 'projection_source_version', 'assistant_public_schema_revision',
            '_search_count_query', '_search_count_actor', '_search_count_proof', 'unit_checksum', 'document_checksum', 'rag_source_checksum', 'estimate_projection', 'related_field_projection']));
    }

    private static function sharedSourceContext(array $view): array
    {
        $references = $view['source_refs'];
        if (count($references) < 2 || array_key_exists('source_context', $view)) { return $view; }
        $first = reset($references);
        if (!is_array($first)) { return $view; }
        $common = array_intersect_key($first, array_flip(['entity_type', 'organization_id', 'content_scope', 'fetched_at',
            'project_id', 'estimate_id', 'contract_id']));
        foreach ($references as $reference) {
            if (!is_array($reference)) { return $view; }
            foreach ($common as $key => $value) {
                if (!array_key_exists($key, $reference) || $reference[$key] !== $value) { unset($common[$key]); }
            }
        }
        if ($common === []) { return $view; }
        $view['source_context'] = $common;
        $view['source_refs'] = array_map(static fn (array $reference): array => array_diff_key($reference, $common), $references);
        return $view;
    }

    private static function position(mixed $position): mixed
    {
        if (!is_array($position)) { return $position; }
        if (is_string($position['version'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $position['version']) === 1) {
            unset($position['version']);
        }
        return $position;
    }

    private static function evidence(array $evidence): array
    {
        unset($evidence['version'], $evidence['source_version'], $evidence['checksum']);
        foreach ($evidence['rows'] ?? [] as $index => $row) {
            if (!is_array($row)) { continue; }
            unset($row['version'], $row['source_version']);
            if (is_array($row['source_ref'] ?? null)) { $row['source_ref'] = self::reference($row['source_ref']); }
            $evidence['rows'][$index] = $row;
        }
        if (is_array($evidence['source_refs'] ?? null)) {
            $evidence['source_refs'] = array_map(self::reference(...), $evidence['source_refs']);
        }
        if (is_array($evidence['positions'] ?? null)) {
            $evidence['positions'] = array_map(self::position(...), $evidence['positions']);
        }
        return $evidence;
    }
}
