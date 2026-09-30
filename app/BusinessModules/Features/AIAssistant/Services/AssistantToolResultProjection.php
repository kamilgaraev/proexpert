<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantToolResultProjection
{
    public static function forProvider(string $toolName, array $result): array
    {
        if ($toolName === 'get_material_stock') {
            return array_intersect_key($result, array_flip(['status', 'stock', 'quantity_scope', 'server_formatted_answer', 'validation_status', 'error', 'reason']));
        }
        $view = $result;
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
                $rows[self::identity($row['entity_type'] ?? '', $row['id'] ?? $row['entity_id'] ?? '')] = $row['fields'];
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
        return array_diff_key($reference, array_flip(['version', 'source_version', 'checksum', 'checked_fields',
            'required_permissions', 'required_domains', 'projection_source_version', 'assistant_public_schema_revision']));
    }

    private static function sharedSourceContext(array $view): array
    {
        $references = $view['source_refs'];
        if (count($references) < 2 || array_key_exists('source_context', $view)) { return $view; }
        $first = reset($references);
        if (!is_array($first)) { return $view; }
        $common = array_intersect_key($first, array_flip(['organization_id', 'content_scope', 'fetched_at',
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
