<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomIdentityObservation;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use Brick\Math\BigDecimal;
use Throwable;

final class NativeNumericFactFactory
{
    public function spreadsheet(DocumentUnitExecutionContext $context, array $native): ?DocumentUnitPublication
    {
        $cells = is_array($native['cells'] ?? null) ? $native['cells'] : [];
        $headers = is_array($native['header_cells'] ?? null) ? $native['header_cells'] : [];
        $columns = [];
        $ambiguous = false;
        $headerRow = 0;
        foreach ($headers as $cell) {
            if (! is_array($cell) || preg_match('/\A([A-Z]+)([1-9][0-9]*)\z/', (string) ($cell['address'] ?? ''), $address) !== 1) {
                continue;
            }
            $name = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower((string) ($cell['value'] ?? '')));
            $field = match ($name) {
                'entitykey', 'сущность', 'объект' => 'entity',
                'facttype', 'parameter', 'параметр' => 'parameter',
                'value', 'значение', 'quantity', 'количество', 'колво', 'объем', 'объём' => 'value',
                'unit', 'ед', 'едизм', 'единицаизмерения' => 'unit',
                'floor', 'этаж' => 'floor_id',
                'zone', 'зона' => 'zone_id',
                default => null,
            };
            if ($field !== null) {
                $ambiguous = $ambiguous || isset($columns[$field]);
                $columns[$field] = $address[1];
            }
            $headerRow = max($headerRow, (int) $address[2]);
        }
        if (! isset($columns['value'], $columns['unit'])) {
            return null;
        }
        if ($ambiguous) {
            return new DocumentUnitPublication([], [], [['role' => 'native', 'index' => null, 'reason_code' => 'native_table_headers_ambiguous']]);
        }
        $rows = [];
        foreach ($cells as $cell) {
            if (! is_array($cell) || preg_match('/\A([A-Z]+)([1-9][0-9]*)\z/', (string) ($cell['address'] ?? ''), $address) !== 1) {
                continue;
            }
            if ((int) $address[2] > $headerRow) {
                $rows[(int) $address[2]][$address[1]] = $cell;
            }
        }
        $records = [];
        foreach ($rows as $row => $cellsByColumn) {
            $cell = $cellsByColumn[$columns['value']] ?? null;
            if (! is_array($cell)) {
                continue;
            }
            $entity = isset($columns['entity']) ? (string) ($cellsByColumn[$columns['entity']]['value'] ?? '') : '';
            $parameter = FactVocabulary::parameter(isset($columns['parameter']) ? (string) ($cellsByColumn[$columns['parameter']]['value'] ?? '') : 'quantity');
            $kind = FactVocabulary::entityType($entity);
            $location = [];
            foreach (['floor_id', 'zone_id'] as $field) {
                if (isset($columns[$field])) {
                    $location[$field] = mb_substr(trim((string) ($cellsByColumn[$columns[$field]]['value'] ?? '')), 0, 120);
                }
            }
            $key = ($kind ?? 'quantity').':native.'.$context->documentId.'.'.$context->index.'.'.substr(hash('sha256', json_encode([$entity !== '' ? $entity : 'row:'.$row, $location], JSON_THROW_ON_ERROR)), 0, 32);
            $formula = $cell['formula'] ?? null;
            $value = $formula === null ? ($cell['raw_value'] ?? $cell['value'] ?? null) : ($cell['cached_value'] ?? null);
            $rawUnit = (string) ($cellsByColumn[$columns['unit']]['value'] ?? '');
            $document = FactVocabulary::documentObservation($parameter, $entity,
                ['type' => 'string', 'data' => $value], trim($rawUnit) === '' ? null : $rawUnit);
            $roomName = $parameter === 'room_name' ? RoomIdentityObservation::label($entity,
                ['type' => 'string', 'data' => $value], trim($rawUnit) === '' ? null : $rawUnit) : null;
            if ($roomName !== null) {
                $value = $roomName;
            }
            if ($document !== null) {
                $key = $document['semantic_type'].':native.'.$context->documentId.'.'.$context->index.'.'.substr(hash('sha256', json_encode([$entity, $location], JSON_THROW_ON_ERROR)), 0, 32);
                $location['source_entity_key'] = $entity;
            }
            if ($kind !== null && in_array($kind, FactVocabulary::engineeringTypes(), true)) {
                $location['source_entity_key'] = $entity;
            }
            $records[] = ['entity' => $key, 'parameter' => $parameter, 'value' => $value,
                'unit' => $document !== null || $roomName !== null ? null : $rawUnit,
                'literal_type' => $document !== null || $roomName !== null ? 'string'
                    : ($parameter === 'room_name' ? 'invalid_room_identity'
                        : (FactVocabulary::documentDomain($parameter) !== null ? 'invalid_document_context' : 'number')),
                'reference' => 'xlsx:sheet:'.(string) ($native['sheet'] ?? '').'!'.$cell['address'],
                'confirmed' => $formula === null, 'reason' => $formula === null ? null : 'native_formula_requires_confirmation', 'location' => $location];
        }

        return $this->publication($context, $records);
    }

    public function cad(DocumentUnitExecutionContext $context, array $geometry): ?DocumentUnitPublication
    {
        $records = [];
        foreach ($geometry['dimensions'] ?? [] as $dimension) {
            if (! is_array($dimension) || ! array_key_exists('measurement', $dimension)
                || ! in_array($dimension['dimension_type'] ?? null, [0, 1, 3, 4], true)
                || ($dimension['measurement_space'] ?? null) !== 'entity_coordinates') {
                continue;
            }
            $confirmed = ($geometry['unit_status'] ?? null) === 'confirmed'
                && mb_strtolower((string) ($dimension['layout'] ?? '')) === 'model'
                && ! isset($dimension['block']) && ! isset($dimension['transform']);
            $handle = (string) ($dimension['handle'] ?? '');
            $records[] = ['entity' => 'dimension:native.'.$context->documentId.'.'.$context->index.'.'.substr(hash('sha256', $handle), 0, 32),
                'parameter' => 'dimension_chain', 'value' => $dimension['measurement'], 'unit' => $geometry['source_unit'] ?? null,
                'reference' => 'cad:dimension:'.$handle, 'confirmed' => $confirmed,
                'reason' => $confirmed ? null : 'native_dimension_scope_or_unit_requires_confirmation'];
        }

        return $this->publication($context, $records);
    }

    private function publication(DocumentUnitExecutionContext $context, array $records): ?DocumentUnitPublication
    {
        $claims = [];
        $decisions = [];
        $quarantined = [];
        $verifiedFingerprints = [];
        foreach ($records as $index => $record) {
            if (in_array($record['literal_type'] ?? null, ['invalid_document_context', 'invalid_room_identity'], true)) {
                $quarantined[] = ['role' => 'native', 'index' => $index,
                    'reason_code' => $record['literal_type'] === 'invalid_room_identity' ? 'native_room_identity_invalid' : 'native_document_context_invalid'];

                continue;
            }
            if (($record['literal_type'] ?? null) === 'string') {
                $claims[] = new ObservationClaim('literal:native:'.($index + 1), 'observer_literal', $record['entity'], $record['parameter'],
                    ['type' => 'string', 'data' => $record['value']], null, 'literal:source:'.($index + 1), $record['confirmed'],
                    $context->organizationId, $context->projectId, $context->sessionId, $context->sourceVersion,
                    ['document_id' => $context->documentId, 'page' => $context->index,
                        'unit_type' => $context->type->value, 'unit_index' => $context->index,
                        'native_reference' => $record['reference'], ...($record['location'] ?? [])], 0.0);
                $claim = $claims[array_key_last($claims)];
                $decisions[] = new ArbitrationDecision($claim->id, $record['confirmed'] ? 'accepted' : 'candidate', [$claim->id], [$claim->evidenceRef],
                    $record['reason'] ?? 'native_literal_document_context',
                    ['entity_key' => $claim->entityKey, 'fact_type' => $claim->factType, 'value' => $claim->value, 'unit' => null, 'source_claim_id' => $claim->id]);

                continue;
            }
            $value = $record['value'];
            $unit = $record['unit'];
            $reason = null;
            if (! is_string($record['parameter']) || preg_match('/\A[a-zA-Z0-9._:-]{1,120}\z/', $record['parameter']) !== 1) {
                $reason = 'native_parameter_missing';
            } elseif ((! is_string($value) && ! is_int($value) && ! is_float($value)) || (is_float($value) && ! is_finite($value))) {
                $reason = 'native_numeric_value_missing';
            } elseif (! is_string($unit) || ! FactVocabulary::numericUnit($unit)) {
                $reason = 'native_numeric_unit_missing';
            } else {
                try {
                    $value = (string) BigDecimal::of((string) $value);
                    if (! CanonicalSourceDecimal::isPositive($value) && ! ($record['parameter'] === 'slope_rise' && CanonicalSourceDecimal::isNonNegative($value))) {
                        $reason = 'native_numeric_precision_or_value_requires_confirmation';
                    } elseif ((in_array(FactVocabulary::entityType($record['entity']), FactVocabulary::engineeringTypes(), true)
                            || in_array($record['parameter'], FactVocabulary::engineeringParameters(), true))
                        && FactVocabulary::engineeringObservation($record['entity'], $record['parameter'], $value, $unit) === null) {
                        $reason = 'native_engineering_measurement_invalid';
                    }
                } catch (Throwable) {
                    $reason = 'native_numeric_value_invalid';
                }
            }
            if ($reason !== null) {
                $quarantined[] = ['role' => 'native', 'index' => $index, 'reason_code' => $reason];

                continue;
            }
            $id = 'literal:native:'.($index + 1);
            $reference = 'literal:source:'.($index + 1);
            $claim = new ObservationClaim($id, 'observer_literal', $record['entity'], $record['parameter'],
                ['type' => 'number', 'data' => $value], $unit, $reference, $record['confirmed'],
                $context->organizationId, $context->projectId, $context->sessionId, $context->sourceVersion,
                ['document_id' => $context->documentId, 'page' => $context->index,
                    'unit_type' => $context->type->value, 'unit_index' => $context->index,
                    'native_reference' => $record['reference'], ...($record['location'] ?? [])], 0.0);
            $claims[] = $claim;
            if ($record['confirmed']) {
                $verifiedFingerprints[] = VerifiedNativeNumericSources::fingerprint($claim);
            }
            $decisions[] = new ArbitrationDecision($id, $record['confirmed'] ? 'accepted' : 'candidate', [$id], [$reference],
                $record['reason'] ?? 'native_literal_number',
                ['entity_key' => $claim->entityKey, 'fact_type' => $claim->factType, 'value' => $claim->value, 'unit' => $claim->unit, 'source_claim_id' => $id]);
        }

        return $claims === [] && $quarantined === [] ? null : new DocumentUnitPublication($claims, $decisions, $quarantined,
            new VerifiedNativeNumericSources($verifiedFingerprints));
    }
}
