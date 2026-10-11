<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DispatchDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Database\Connection;

final readonly class EstimatorDocumentCreditBinding
{
    public function __construct(private Connection $database) {}

    public function bind(EstimateGenerationSession $session, string $operationId): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new UsageInvariantViolation('estimator_document_credit_binding_requires_transaction');
        }
        $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->where('session_id', $session->id)->whereIn('status', ['reserved', 'running'])->lockForUpdate()->first();
        if ($operation === null) {
            throw new UsageInvariantViolation('estimator_document_credit_operation_missing');
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $manifest = $plan['documents'] ?? [];
        if (! hash_equals((string) $operation->manifest_hash, hash('sha256', CanonicalPipelineJson::encode($manifest)))) {
            throw new UsageInvariantViolation('estimator_document_approved_manifest_invalid');
        }
        foreach ($manifest as $entry) {
            $document = $session->documents()->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                ->whereKey($entry['document_id'])->lockForUpdate()->firstOrFail();
            if (DocumentSourceVersion::fromDocument($document) !== $entry['source_version'] || $document->mime_type !== $entry['mime_type']) {
                throw new UsageInvariantViolation('estimator_document_approved_source_changed');
            }
            $units = EstimateGenerationProcessingUnit::query()->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                ->where('session_id', $session->id)->where('document_id', $document->id)->where('source_version', $entry['source_version'])
                ->orderBy('id')->lockForUpdate()->get();
            if ($units->pluck('id')->map(static fn ($id): int => (int) $id)->all() !== array_column($entry['units'], 'id')) {
                throw new UsageInvariantViolation('estimator_document_approved_units_changed');
            }
            foreach ($units as $index => $unit) {
                if (! self::matches($unit, $entry['units'][$index])) {
                    throw new UsageInvariantViolation('estimator_document_approved_unit_changed');
                }
                if ($unit->status->value === 'completed') {
                    continue;
                }
                $metadata = $unit->metadata ?? [];
                $priorId = $metadata['credit_operation_id'] ?? null;
                if ($priorId !== null && $priorId !== $operationId && $this->database->table('estimate_generation_credit_operations')
                    ->where('operation_id', $priorId)->whereIn('status', ['reserved', 'running'])->exists()) {
                    throw new UsageInvariantViolation('estimator_document_unit_has_another_active_operation');
                }
                $unit->update(['metadata' => [...$metadata, 'credit_operation_id' => $operationId,
                    'credit_manifest_hash' => $operation->manifest_hash]]);
            }
            if ($units->isEmpty()) {
                throw new UsageInvariantViolation('estimator_document_preparation_required');
            }
            $documentId = (int) $document->id;
            $sourceVersion = $entry['source_version'];
            $this->database->afterCommit(static fn () => app(DispatchDocumentProcessingUnits::class)->forDocument($documentId, $sourceVersion));
        }
    }

    public function canDispatch(EstimateGenerationProcessingUnit $unit): bool
    {
        $metadata = $unit->metadata ?? [];
        $operationId = $metadata['credit_operation_id'] ?? null;
        if ($operationId === null) {
            return ! \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $unit->organization_id);
        }
        if (! \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $unit->organization_id)) {
            return false;
        }
        $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)
            ->where('organization_id', $unit->organization_id)->where('project_id', $unit->project_id)->where('session_id', $unit->session_id)
            ->whereIn('status', ['reserved', 'running'])->first(['processing_plan', 'manifest_hash']);
        if ($operation === null || ($metadata['credit_manifest_hash'] ?? null) !== $operation->manifest_hash) {
            return false;
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        foreach ($plan['documents'] ?? [] as $entry) {
            if ($entry['document_id'] !== (int) $unit->document_id || $entry['source_version'] !== $unit->source_version) {
                continue;
            }
            foreach ($entry['units'] as $pin) {
                if ($pin['id'] === (int) $unit->id && self::matches($unit, $pin)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function matches(EstimateGenerationProcessingUnit $unit, array $pin): bool
    {
        return $pin['type'] === $unit->unit_type->value && $pin['index'] === (int) $unit->unit_index
            && array_key_exists('processing_lineage_id', $pin)
            && $pin['processing_lineage_id'] === ($unit->metadata['processing_attempt_id'] ?? null)
            && hash_equals($pin['locator_hash'], hash('sha256', CanonicalPipelineJson::encode($unit->locator ?? [])));
    }
}
