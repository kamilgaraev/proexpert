<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use LogicException;

final class AssistantEstimateStructuredFacts
{
    private const IDENTITY_FIELDS = ['number', 'name', 'status', 'estimate_date'];
    private const POSITION_FIELDS = ['position_number', 'name', 'quantity', 'total_amount'];
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
            $reference['content_scope'] = 'structured';
            $reference['checked_fields'] = self::POSITION_FIELDS;
            $reference['required_permissions'] = self::PERMISSIONS;
            $reference['required_domains'] = ['estimates'];
            $reference['source_version'] = $position['version'];
            $rows[] = self::row('estimate_item', $position['id'], array_intersect_key($position, array_fill_keys(self::POSITION_FIELDS, true)), $reference);
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

        return self::row('estimate', $header['id'], array_intersect_key($header, array_fill_keys(self::IDENTITY_FIELDS, true)), $source);
    }

    private static function row(string $type, int $id, array $fields, array $source): array
    {
        $row = ['entity_type' => $type, 'entity_id' => $id, 'fields' => $fields,
            'source_ref' => $source, 'source_version' => $source['source_version'] ?? null];
        $row['version'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        return $row;
    }
}
