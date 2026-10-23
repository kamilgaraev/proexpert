<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomIdentityObservation;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomLocationObservation;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomNumberObservation;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelInvariant;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\SourceDocumentRole;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;
use Throwable;

final class NativeNumericFactFactory
{
    public function readLengthOperand(DocumentUnitExecutionContext $context, string $artifactContent, string $rawFactId,
        ProjectModelRepository $models, EvidenceRepository $evidence): ?array
    {
        try {
            ProjectModelInvariant::id($rawFactId, 'Native operand fact');
        } catch (\InvalidArgumentException) {
            return null;
        }
        $unit = EstimateGenerationProcessingUnit::query()->with('document', 'page')
            ->where('organization_id', $context->organizationId)->where('project_id', $context->projectId)
            ->where('session_id', $context->sessionId)->where('document_id', $context->documentId)
            ->where('unit_type', DocumentUnitType::SpreadsheetSheet->value)->where('unit_index', $context->index)
            ->where('status', DocumentProcessingUnitStatus::Completed->value)->where('source_version', $context->sourceVersion)
            ->find($context->unitId);
        $document = $unit?->document;
        $page = $unit?->page;
        if (! $document instanceof EstimateGenerationDocument || $page === null
            || $context->type !== DocumentUnitType::SpreadsheetSheet
            || [(int) $document->organization_id, (int) $document->project_id, (int) $document->session_id]
                !== [$context->organizationId, $context->projectId, $context->sessionId]
            || $document->source_version !== $context->sourceVersion || $document->status === 'ignored'
            || $document->ignored_at !== null || $document->processing_control_status === 'ignored'
            || $document->storage_path !== $context->storagePath || $document->filename !== $context->filename
            || $page->excluded_at !== null || $page->source_version !== $context->sourceVersion
            || (int) $page->document_id !== $context->documentId || (int) $page->page_number !== $context->index
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $document->checksum_sha256) !== 1
            || CanonicalPipelineJson::encode((array) $unit->locator) !== CanonicalPipelineJson::encode($context->locator)) {
            return null;
        }
        try {
            $source = NativeSpreadsheetLiteralSources::fromArtifact($context, $artifactContent);
        } catch (\JsonException|DocumentUnitProcessingException) {
            return null;
        }
        if ($source === null) {
            return null;
        }
        $fact = $models->factsByIds($context->organizationId, $context->projectId, $context->sessionId, [$rawFactId])[0] ?? null;
        if (! $fact instanceof Fact || $fact->sourceVersion !== $context->sourceVersion
            || [$fact->organizationId, $fact->projectId, $fact->sessionId]
                !== [$context->organizationId, $context->projectId, $context->sessionId]
            || $fact->status !== 'confirmed' || $fact->origin !== 'document' || $fact->semanticAttributes !== []
            || count($fact->evidenceIds) !== 1 || ! is_string($fact->value) || ! CanonicalSourceDecimal::isPositive($fact->value)
            || FactVocabulary::factor('length', $fact->unit) === null) {
            return null;
        }
        $current = $models->currentFacts($context->organizationId, $context->projectId, $context->sessionId, $fact->entityId, 10_001);
        if (count($current) > 10_000 || count(array_filter($current,
            static fn (Fact $candidate): bool => $candidate->id === $fact->id && $candidate->version === $fact->version
                && CanonicalPipelineJson::encode($candidate->jsonSerialize()) === CanonicalPipelineJson::encode($fact->jsonSerialize()))) !== 1
            || preg_match('/\Aevidence:([1-9][0-9]{0,17})\z/', $fact->evidenceIds[0], $identity) !== 1) {
            return null;
        }
        $node = $evidence->node($context->organizationId, $context->projectId, $context->sessionId, (int) $identity[1]);
        if ($node === null || $node->invalidatedAt !== null || $node->type !== EvidenceType::SourceFact
            || $node->sourceType !== EvidenceSourceType::Document || $node->sourceRef !== 'document:'.$context->documentId
            || $node->sourceVersion !== $context->sourceVersion || $node->producerName !== 'native_numeric_parser'
            || ($node->locator['document_id'] ?? null) !== $context->documentId
            || ($node->locator['unit_type'] ?? null) !== DocumentUnitType::SpreadsheetSheet->value
            || ($node->locator['unit_index'] ?? null) !== $context->index
            || ! is_string($node->locator['native_reference'] ?? null)
            || ($node->value['fact_value'] ?? null) !== $fact->value) {
            return null;
        }
        $domainEvidence = new Evidence($fact->evidenceIds[0], $node->organizationId, $node->projectId, $node->sessionId,
            $node->sourceVersion, $node->sourceRef, $node->sourceType->value,
            nativeReference: $node->locator['native_reference'], producerName: $node->producerName, fingerprint: $node->fingerprint);
        $operand = $source->lengthOperand($node->locator['native_reference']);
        if (! (new NativeNumericFactProof)->matches($fact, $domainEvidence) || $operand === null
            || $operand['value'] !== $fact->value || $operand['unit'] !== $fact->unit) {
            return null;
        }

        return ['schema_version' => 1, ...$operand, 'raw_fact_id' => $fact->id, 'raw_fact_version' => $fact->version,
            'raw_fact_type' => $fact->type, 'raw_entity_id' => $fact->entityId,
            'raw_value_hash' => hash('sha256', json_encode($fact->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'evidence_id' => $fact->evidenceIds[0], 'evidence_fingerprint' => $node->fingerprint,
            'evidence_invalidation_version' => $node->invalidationVersion,
            'organization_id' => $context->organizationId, 'project_id' => $context->projectId, 'session_id' => $context->sessionId,
            'document_id' => $context->documentId, 'document_source_version' => $context->sourceVersion,
            'document_checksum_sha256' => $document->checksum_sha256, 'unit_id' => $context->unitId, 'unit_index' => $context->index,
            'artifact_sha256' => $context->locator['artifact_sha256'], 'artifact_bytes' => $context->locator['artifact_bytes']];
    }

    public function spreadsheet(DocumentUnitExecutionContext $context, array $native, ?NativeSpreadsheetLiteralSources $literalSources = null): ?DocumentUnitPublication
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
                'documentrole', 'рольдокумента', 'рольлиста' => 'document_role',
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
        $literalIdentityHeaders = array_filter($headers, static fn (mixed $cell): bool => is_array($cell) && ($cell['formula'] ?? null) !== null) === [];
        foreach ($rows as $row => $cellsByColumn) {
            $cell = $cellsByColumn[$columns['value']] ?? null;
            if (! is_array($cell)) {
                continue;
            }
            $entity = isset($columns['entity']) ? (string) ($cellsByColumn[$columns['entity']]['value'] ?? '') : '';
            $parameter = FactVocabulary::parameter(isset($columns['parameter']) ? (string) ($cellsByColumn[$columns['parameter']]['value'] ?? '') : 'quantity');
            $kind = FactVocabulary::entityType($entity);
            $location = [];
            $locationRequiresConfirmation = false;
            foreach (['floor_id', 'zone_id'] as $field) {
                if (isset($columns[$field])) {
                    $locationCell = $cellsByColumn[$columns[$field]] ?? [];
                    $locationValue = $locationCell['raw_value'] ?? $locationCell['value'] ?? null;
                    $literal = (is_string($locationValue) || is_int($locationValue)
                        || (is_float($locationValue) && is_finite($locationValue))) ? trim((string) $locationValue) : '';
                    if ($field === 'zone_id' && ($locationCell['formula'] ?? null) === null
                        && ($locationValue === null || (is_string($locationValue) && trim($locationValue) === ''))) {
                        continue;
                    }
                    $literal = RoomLocationObservation::identifier($literal);
                    if (($locationCell['formula'] ?? null) !== null || $literal === null) {
                        $locationRequiresConfirmation = true;
                    } else {
                        $location[$field] = $literal;
                    }
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
            $roomNumber = $parameter === 'room_number' ? RoomNumberObservation::label($entity,
                ['type' => 'string', 'data' => $value], trim($rawUnit) === '' ? null : $rawUnit) : null;
            $roomLiteral = $roomName ?? $roomNumber;
            if ($roomLiteral !== null) {
                $value = $roomLiteral;
            }
            if ($document !== null) {
                $key = $document['semantic_type'].':native.'.$context->documentId.'.'.$context->index.'.'.substr(hash('sha256', json_encode([$entity, $location], JSON_THROW_ON_ERROR)), 0, 32);
                $location['source_entity_key'] = $entity;
            }
            if ($kind !== null && in_array($kind, FactVocabulary::engineeringTypes(), true)) {
                $location['source_entity_key'] = $entity;
            }
            if (isset($columns['document_role'])) {
                $roleCell = $cellsByColumn[$columns['document_role']] ?? [];
                $role = ($roleCell['formula'] ?? null) === null
                    ? SourceDocumentRole::normalize($roleCell['raw_value'] ?? $roleCell['value'] ?? null) : null;
                if ($role !== null) {
                    $location['document_role'] = $role;
                }
            }
            $literalIdentityCells = true;
            foreach (['entity', 'parameter'] as $field) {
                $identityCell = $cellsByColumn[$columns[$field] ?? ''] ?? [];
                $literalIdentityCells = $literalIdentityCells && is_string($identityCell['raw_value'] ?? null)
                    && $identityCell['raw_value'] === ($identityCell['value'] ?? null);
            }
            $records[] = ['entity' => $key, 'parameter' => $parameter, 'value' => $value,
                'unit' => $document !== null || $roomLiteral !== null ? null : $rawUnit,
                'literal_type' => $document !== null || $roomLiteral !== null ? 'string'
                    : (in_array($parameter, ['room_name', 'room_number'], true) ? 'invalid_room_identity'
                        : (FactVocabulary::documentDomain($parameter) !== null ? 'invalid_document_context' : 'number')),
                'reference' => 'xlsx:sheet:'.(string) ($native['sheet'] ?? '').'!'.$cell['address'],
                'confirmed' => $formula === null && ! $locationRequiresConfirmation,
                'literal_source_eligible' => $literalIdentityHeaders && $literalIdentityCells
                    && array_filter(array_intersect_key($cellsByColumn, array_flip(array_values($columns))),
                        static fn (array $identityCell): bool => ($identityCell['formula'] ?? null) !== null) === [],
                'reason' => $formula !== null ? 'native_formula_requires_confirmation'
                    : ($locationRequiresConfirmation ? 'native_location_requires_confirmation' : null), 'location' => $location];
        }

        $publication = $this->publication($context, $records);
        if ($publication === null || $literalSources === null) {
            return $publication;
        }
        $eligible = [];
        foreach ($records as $index => $record) {
            if (($record['literal_type'] ?? null) === 'string' && $record['confirmed'] && $record['literal_source_eligible']) {
                $eligible['literal:native:'.($index + 1)] = true;
            }
        }

        return new DocumentUnitPublication($publication->claims, $publication->decisions, $publication->quarantinedItems,
            $publication->numericSources, $literalSources->bind($context, $native,
                array_values(array_filter($publication->claims, static fn (ObservationClaim $claim): bool => isset($eligible[$claim->id])))));
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
