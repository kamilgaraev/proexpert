<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DocumentManifestPublicationFence;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class CreateDocumentProcessingUnits
{
    public function __construct(
        private DocumentUnitDetector $detector,
        private DocumentProcessingStatusService $status,
        private DispatchDocumentProcessingUnits $dispatcher,
        private DocumentSourceReplacementCoordinator $replacement,
        private DocumentManifestPublicationFence $publicationFence,
    ) {}

    /** @return Collection<int, EstimateGenerationProcessingUnit> */
    public function handleClaimed(EstimateGenerationDocument $document, CheckpointClaim $claim): Collection
    {
        return $this->create($document, $claim);
    }

    /** @return Collection<int, EstimateGenerationProcessingUnit> */
    private function create(EstimateGenerationDocument $document, CheckpointClaim $claim): Collection
    {
        $document->loadMissing('session');
        $sourceVersion = DocumentSourceVersion::fromDocument($document);
        $previousSourceVersion = (string) $document->source_version;

        if (
            $document->session === null
            || (int) $document->organization_id !== (int) $document->session->organization_id
            || (int) $document->project_id !== (int) $document->session->project_id
            || (string) $document->storage_path === ''
        ) {
            throw new RuntimeException('estimate_generation.document_scope_invalid');
        }

        $existing = EstimateGenerationProcessingUnit::query()
            ->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)
            ->where('session_id', $document->session_id)
            ->where('document_id', $document->id)
            ->where('source_version', $sourceVersion)
            ->orderBy('unit_type')
            ->orderBy('unit_index')
            ->get();

        if ($existing->isNotEmpty()) {
            $this->publish($claim, fn () => $this->dispatcher->forDocument((int) $document->id, $sourceVersion));

            return $existing;
        }

        try {
            $units = DocumentUnitData::normalize($this->detector->detect($document, $sourceVersion));
            if ($units === []) {
                throw new DocumentManifestNeedsReview('document_units_empty');
            }
        } catch (DocumentManifestNeedsReview $error) {
            $this->publish($claim, fn () => $this->status->markNeedsReview($document, 0.0, [$error->safeCode], [], 'unusable'));

            return collect();
        }
        $models = $this->publish($claim, fn () => $this->replacement->commit(
            (int) $document->organization_id,
            (int) $document->project_id,
            (int) $document->session_id,
            (int) $document->id,
            $previousSourceVersion,
            $sourceVersion,
            function () use ($document, $sourceVersion, $units): Collection {
                EstimateGenerationProcessingUnit::query()
                    ->where('organization_id', $document->organization_id)
                    ->where('project_id', $document->project_id)
                    ->where('session_id', $document->session_id)
                    ->where('document_id', $document->id)
                    ->where('source_version', '<>', $sourceVersion)
                    ->whereNotIn('status', [DocumentProcessingUnitStatus::Completed->value, DocumentProcessingUnitStatus::Superseded->value])
                    ->update([
                        'status' => DocumentProcessingUnitStatus::Superseded->value,
                        'claim_token' => null,
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);

                $sourceChanged = (string) $document->source_version !== $sourceVersion;
                $document->forceFill([
                    'source_version' => $sourceVersion,
                    ...($sourceChanged ? [
                        'units_finalized_source_version' => null,
                        'units_reconciled_source_version' => null,
                        'units_reconcile_claim_token' => null,
                        'units_reconcile_lease_expires_at' => null,
                    ] : []),
                ])->save();

                foreach ($units as $unit) {
                    DB::table('estimate_generation_processing_units')->insertOrIgnore([
                        'organization_id' => $document->organization_id,
                        'project_id' => $document->project_id,
                        'session_id' => $document->session_id,
                        'document_id' => $document->id,
                        'unit_type' => $unit->type->value,
                        'unit_index' => $unit->index,
                        'source_version' => $unit->sourceVersion,
                        'status' => DocumentProcessingUnitStatus::Pending->value,
                        'attempt_count' => 0,
                        'output_count' => 0,
                        'locator' => json_encode($unit->locator, JSON_THROW_ON_ERROR),
                        'metadata' => '{}',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                }

                return EstimateGenerationProcessingUnit::query()
                    ->where('organization_id', $document->organization_id)
                    ->where('project_id', $document->project_id)
                    ->where('session_id', $document->session_id)
                    ->where('document_id', $document->id)
                    ->where('source_version', $sourceVersion)
                    ->orderBy('unit_type')
                    ->orderBy('unit_index')
                    ->get();
            },
        ));

        $this->dispatcher->forDocument((int) $document->id, $sourceVersion);

        return $models;
    }

    private function publish(CheckpointClaim $claim, callable $publication): mixed
    {
        return $this->publicationFence->publish($claim, $publication);
    }
}
