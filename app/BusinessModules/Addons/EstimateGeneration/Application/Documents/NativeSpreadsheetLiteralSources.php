<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final readonly class NativeSpreadsheetLiteralSources
{
    private function __construct(
        private DocumentUnitExecutionContext $context,
        private string $artifactHash,
        private array $native,
        private array $bindings = [],
    ) {}

    public static function fromArtifact(DocumentUnitExecutionContext $context, string $content): ?self
    {
        $locator = $context->locator;
        if (strtolower(pathinfo($context->filename, PATHINFO_EXTENSION)) !== 'xlsx'
            || ! isset($locator['artifact_sha256'], $locator['artifact_bytes'], $locator['source_version'])) {
            return null;
        }
        if (($locator['content_type'] ?? null) === 'application/vnd.most.spreadsheet-sheet+json'
            && ! array_key_exists('artifact_kind', $locator) && ! array_key_exists('artifact_schema_version', $locator)) {
            return null;
        }
        $hash = 'sha256:'.hash('sha256', $content);
        if ($context->type !== DocumentUnitType::SpreadsheetSheet
            || min($context->unitId, $context->organizationId, $context->projectId, $context->sessionId, $context->documentId, $context->index) < 1
            || $locator['source_version'] !== $context->sourceVersion
            || ! is_string($locator['artifact_path'] ?? null)
            || ! str_starts_with($locator['artifact_path'], 'org-'.$context->organizationId.'/')
            || $locator['artifact_sha256'] !== $hash || $locator['artifact_bytes'] !== strlen($content)
            || ($locator['source_kind'] ?? null) !== 'spreadsheet'
            || ($locator['artifact_kind'] ?? null) !== 'spreadsheet_sheet'
            || ($locator['artifact_schema_version'] ?? null) !== 1) {
            throw new DocumentUnitProcessingException('spreadsheet_literal_artifact_binding_invalid');
        }
        $payload = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['schema_version'] ?? null) !== 1
            || ($payload['source_kind'] ?? null) !== 'spreadsheet'
            || ! is_array($payload['native_structure'] ?? null)
            || ! in_array($payload['native_structure']['status'] ?? null, ['available', 'partial'], true)) {
            throw new DocumentUnitProcessingException('spreadsheet_literal_artifact_contract_invalid');
        }

        return new self($context, $hash, $payload['native_structure']);
    }

    public function bind(DocumentUnitExecutionContext $context, array $native, array $claims): self
    {
        return $this->bindLiterals($context, $native, $claims);
    }

    public function bindReviewed(DocumentUnitExecutionContext $context, array $native, array $claims,
        \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact $review): self
    {
        if ([$review->organizationId, $review->projectId, $review->sessionId] !== [$context->organizationId, $context->projectId, $context->sessionId]
            || $review->type !== 'evaluation_answer' || $review->origin !== 'user_input' || $review->status !== 'confirmed'
            || $review->evidenceIds === [] || ($review->value['kind'] ?? null) !== 'document_cell_review'
            || ($review->value['decision'] ?? null) !== 'accept'
            || ($review->value['identity_confirmation'] ?? null) !== 'new_document_local_equipment'
            || count($claims) !== 1 || ($review->value['entity_key'] ?? null) !== $claims[0]->entityKey
            || $claims[0]->factType !== 'equipment_specification'
            || ($review->value['binding']['document_id'] ?? null) !== $context->documentId
            || ($review->value['binding']['unit_id'] ?? null) !== $context->unitId
            || ($review->value['binding']['source_version'] ?? null) !== $context->sourceVersion
            || ($review->value['binding']['artifact_sha256'] ?? null) !== $this->artifactHash) {
            throw new DocumentUnitProcessingException('document_cell_review_literal_invalid');
        }

        return $this->bindLiterals($context, $native, $claims, $review);
    }

    private function bindLiterals(DocumentUnitExecutionContext $context, array $native, array $claims,
        ?\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact $review = null): self
    {
        if ([$context->organizationId, $context->projectId, $context->sessionId, $context->documentId, $context->unitId, $context->index, $context->sourceVersion]
            !== [$this->context->organizationId, $this->context->projectId, $this->context->sessionId, $this->context->documentId, $this->context->unitId, $this->context->index, $this->context->sourceVersion]
            || CanonicalPipelineJson::encode($native) !== CanonicalPipelineJson::encode($this->native)) {
            throw new DocumentUnitProcessingException('spreadsheet_literal_source_scope_invalid');
        }
        $cells = [];
        foreach ($this->native['cells'] ?? [] as $cell) {
            if (is_array($cell) && is_string($cell['address'] ?? null)) {
                $reference = 'xlsx:sheet:'.(string) ($this->native['sheet'] ?? '').'!'.$cell['address'];
                $cells[$reference] = array_key_exists($reference, $cells) ? null : $cell;
            }
        }
        $bindings = [];
        foreach ($claims as $claim) {
            $cell = $cells[$claim->locator['native_reference'] ?? ''] ?? null;
            if ([$claim->organizationId, $claim->projectId, $claim->sessionId, $claim->sourceVersion]
                !== [$context->organizationId, $context->projectId, $context->sessionId, $context->sourceVersion]
                || ($claim->locator['document_id'] ?? null) !== $context->documentId
                || ($claim->locator['unit_type'] ?? null) !== $context->type->value
                || ($claim->locator['unit_index'] ?? null) !== $context->index
                || ! $claim->explicitEvidence || $claim->unit !== null || ($claim->value['type'] ?? null) !== 'string'
                || ! is_array($cell) || ($cell['formula'] ?? null) !== null
                || ! is_string($cell['raw_value'] ?? null) || trim($cell['raw_value']) === ''
                || $cell['raw_value'] !== ($claim->value['data'] ?? null)
                || FactVocabulary::documentObservation($claim->factType, $review === null ? (string) ($claim->locator['source_entity_key'] ?? '') : $claim->entityKey, $claim->value, null) === null) {
                continue;
            }
            $bindings[self::fingerprint($claim)] = [
                'schema_version' => 1, 'producer' => 'native_spreadsheet_literal:v1',
                'artifact_sha256' => $this->artifactHash, 'document_id' => $context->documentId,
                'unit_id' => $context->unitId, 'cell' => $cell['address'],
                'entity_key' => $claim->entityKey, 'fact_type' => $claim->factType,
                'value_hash' => hash('sha256', json_encode($claim->value['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'organization_id' => $context->organizationId, 'project_id' => $context->projectId,
                'session_id' => $context->sessionId, 'source_version' => $context->sourceVersion,
            ];
        }

        return new self($context, $this->artifactHash, $this->native, $bindings);
    }

    public function binding(ObservationClaim $claim, int $documentId, int $unitIndex): ?array
    {
        if ($documentId !== $this->context->documentId || $unitIndex !== $this->context->index) {
            return null;
        }

        return $this->bindings[self::fingerprint($claim)] ?? null;
    }

    private static function fingerprint(ObservationClaim $claim): string
    {
        return hash('sha256', CanonicalPipelineJson::encode([
            $claim->organizationId, $claim->projectId, $claim->sessionId, $claim->sourceVersion,
            $claim->entityKey, $claim->factType, $claim->value, $claim->unit, $claim->locator,
        ]));
    }
}
