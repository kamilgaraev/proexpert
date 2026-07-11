<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class CreateDocumentProcessingUnits
{
    public function __construct(
        private DocumentUnitDetector $detector,
        private DocumentProcessingStatusService $status,
    ) {}

    /** @return Collection<int, EstimateGenerationProcessingUnit> */
    public function handle(EstimateGenerationDocument $document): Collection
    {
        $document->loadMissing('session');
        $sourceVersion = DocumentSourceVersion::fromDocument($document);

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
            return $existing;
        }

        try {
            $units = DocumentUnitData::normalize($this->detector->detect($document, $sourceVersion));
        } catch (DocumentManifestNeedsReview $error) {
            $this->status->markNeedsReview($document, 0.0, [$error->safeCode], [], 'unusable');

            return collect();
        }
        $newUnitIds = [];

        $models = DB::transaction(function () use ($document, $sourceVersion, $units, &$newUnitIds): Collection {
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

            $document->forceFill(['source_version' => $sourceVersion])->save();

            foreach ($units as $unit) {
                $inserted = DB::table('estimate_generation_processing_units')->insertOrIgnore([
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

                if ($inserted === 1) {
                    $newUnitIds[] = (int) EstimateGenerationProcessingUnit::query()
                        ->where('document_id', $document->id)
                        ->where('unit_type', $unit->type->value)
                        ->where('unit_index', $unit->index)
                        ->where('source_version', $sourceVersion)
                        ->value('id');
                }
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
        }, 3);

        foreach (array_values(array_unique($newUnitIds)) as $unitId) {
            ProcessEstimateGenerationUnitJob::dispatch($unitId, $sourceVersion)
                ->onConnection(ProcessEstimateGenerationUnitJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationUnitJob::QUEUE)
                ->afterCommit();
        }

        return $models;
    }
}
