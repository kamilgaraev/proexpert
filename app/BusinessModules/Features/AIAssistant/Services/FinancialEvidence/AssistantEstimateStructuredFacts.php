<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use LogicException;

final class AssistantEstimateStructuredFacts
{
    private const IDENTITY_FIELDS = ['number', 'name', 'status', 'estimate_date'];

    private const POSITION_FIELDS = ['position_number', 'name', 'quantity', 'total_amount'];

    private const SEARCH_POSITION_FIELDS = ['position_number', 'name', 'normative_rate_code'];

    private const PERMISSIONS = ['budget-estimates.view', 'budget-estimates.finance.view'];

    public static function identity(array $evidence, int $organizationId): array
    {
        return AssistantStructuredFactFormatter::payload([self::identityRow($evidence, $organizationId)], $evidence['fetched_at']);
    }

    public static function positions(array $evidence, array $positions, int $organizationId): array
    {
        $rows = [self::identityRow($evidence, $organizationId)];
        $references = AssistantEstimateEvidenceService::publicSourceReferences($evidence, $positions);
        $byId = [];
        foreach ($references as $reference) {
            if (($reference['entity_type'] ?? null) === 'estimate_item') {
                $byId[(string) $reference['entity_id']] = $reference;
            }
        }
        foreach (array_slice($positions, 0, AssistantStructuredFactFormatter::MAX_ROWS - 1) as $position) {
            $reference = $byId[(string) $position['id']] ?? null;
            if (! is_array($reference) || ($reference['estimate_id'] ?? null) !== ($evidence['estimate']['id'] ?? null)
                || ($reference['organization_id'] ?? null) !== $organizationId
                || ($reference['version'] ?? null) !== ($position['version'] ?? null)
                || ($reference['fetched_at'] ?? null) !== ($evidence['fetched_at'] ?? null)
                || array_diff(self::POSITION_FIELDS, array_keys($position)) !== []) {
                throw new LogicException('estimate_position_evidence_incomplete');
            }
            $fields = array_intersect_key($position, array_fill_keys([...self::POSITION_FIELDS, 'unit', 'unit_price'], true)) + ['currency' => null];
            $reference['content_scope'] = 'structured';
            $reference['checked_fields'] = array_keys($fields);
            $reference['required_permissions'] = self::PERMISSIONS;
            $reference['required_domains'] = ['estimates'];
            $reference['source_version'] = $position['version'];
            $rows[] = self::row('estimate_item', $position['id'], $fields, $reference);
        }
        $payload = AssistantStructuredFactFormatter::payload($rows, $evidence['fetched_at']);
        $payload['structured_fact_evidence']['truncated'] = count($positions) > AssistantStructuredFactFormatter::MAX_ROWS - 1;
        $payload['structured_fact_evidence']['position_page'] = [
            'estimate_id' => (int) $evidence['estimate']['id'],
            'shown' => count($rows) - 1,
            'returned' => count($positions),
            'total' => $evidence['position_count'],
        ];

        return $payload;
    }

    public static function crossEstimatePositions(array $matches, int $organizationId, string $fetchedAt): array
    {
        $rows = [];
        $seenEstimates = [];
        foreach ($matches as $match) {
            $estimate = $match['estimate'] ?? null;
            $position = $match['position'] ?? null;
            if (! is_array($estimate) || ! is_array($position)
                || ($estimate['id'] ?? null) !== ($position['estimate_id'] ?? null)
                || ($position['version'] ?? null) === null) {
                throw new LogicException('estimate_search_evidence_incomplete');
            }

            $estimateId = (int) $estimate['id'];
            $projectId = $estimate['project_id'] === null ? null : (int) $estimate['project_id'];
            if (! isset($seenEstimates[$estimateId])) {
                $estimateSource = [
                    'source_type' => 'estimate', 'entity_type' => 'estimate', 'entity_id' => $estimateId,
                    'organization_id' => $organizationId, 'project_id' => $projectId,
                    'version' => hash('sha256', json_encode([$estimateId, $estimate['number'], $estimate['name'], $match['estimate_updated_at'] ?? null], JSON_THROW_ON_ERROR)),
                    'fetched_at' => $fetchedAt, 'content_scope' => 'structured',
                    'checked_fields' => ['number', 'name'], 'required_permissions' => self::PERMISSIONS,
                    'required_domains' => ['estimates'], 'source_version' => (string) ($match['estimate_updated_at'] ?? ''),
                    'navigation' => ['url' => '/estimates/'.$estimateId],
                ];
                $rows[] = self::row('estimate', $estimateId,
                    ['number' => (string) $estimate['number'], 'name' => (string) $estimate['name']], $estimateSource);
                $seenEstimates[$estimateId] = true;
            }

            $positionId = (int) $position['id'];
            $positionSource = [
                'source_type' => 'estimate', 'entity_type' => 'estimate_item', 'entity_id' => $positionId,
                'estimate_id' => $estimateId, 'organization_id' => $organizationId, 'project_id' => $projectId,
                'version' => $position['version'], 'fetched_at' => $fetchedAt, 'content_scope' => 'structured',
                'checked_fields' => self::SEARCH_POSITION_FIELDS, 'required_permissions' => self::PERMISSIONS,
                'required_domains' => ['estimates'], 'source_version' => (string) ($match['position_updated_at'] ?? ''),
                'navigation' => ['url' => '/estimates/'.$estimateId.'?position_id='.$positionId],
            ];
            $rows[] = self::row('estimate_item', $positionId,
                array_intersect_key($position, array_fill_keys(self::SEARCH_POSITION_FIELDS, true)), $positionSource);
        }

        return AssistantStructuredFactFormatter::payload($rows, $fetchedAt);
    }

    private static function identityRow(array $evidence, int $organizationId): array
    {
        $source = $evidence['source_refs'][0] ?? null;
        $header = $evidence['estimate'] ?? null;
        if (! is_array($source) || ! is_array($header) || ($source['entity_type'] ?? null) !== 'estimate'
            || ($source['entity_id'] ?? null) !== ($header['id'] ?? null)
            || ($source['organization_id'] ?? null) !== $organizationId
            || ($source['fetched_at'] ?? null) !== ($evidence['fetched_at'] ?? null)
            || ($source['version'] ?? null) !== ($evidence['version'] ?? null)
            || ! is_array($source['checked_fields'] ?? null)
            || array_diff(self::IDENTITY_FIELDS, $source['checked_fields']) !== []
            || ! is_array($source['required_permissions'] ?? null)
            || array_diff(self::PERMISSIONS, $source['required_permissions']) !== []
            || array_diff(self::IDENTITY_FIELDS, array_keys($header)) !== []) {
            throw new LogicException('estimate_identity_evidence_incomplete');
        }

        $fields = array_intersect_key($header, array_fill_keys(self::IDENTITY_FIELDS, true));
        if (($evidence['aggregation']['method'] ?? null) === 'sum_top_level_accounted_positions'
            && array_key_exists('total_amount', $evidence['totals'] ?? []) && in_array('total_amount', $source['checked_fields'], true)) {
            $total = $evidence['totals']['total_amount'];
            if ($total !== null && (! is_string($total) || preg_match('/^-?\d+(?:\.\d+)?$/D', $total) !== 1)) {
                throw new LogicException('estimate_total_evidence_incomplete');
            }
            $fields += ['total_amount' => $total, 'currency' => null];
            $source['checked_fields'] = array_values(array_unique([...$source['checked_fields'], 'currency']));
        }

        return self::row('estimate', $header['id'], $fields, $source);
    }

    private static function row(string $type, int $id, array $fields, array $source): array
    {
        $row = ['entity_type' => $type, 'entity_id' => $id, 'fields' => $fields,
            'source_ref' => $source, 'source_version' => $source['source_version'] ?? null];
        $row['version'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        return $row;
    }
}
