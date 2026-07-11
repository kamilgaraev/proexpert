<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Connection;

final readonly class RequireDocumentProcessingReview
{
    public function __construct(
        private Connection $database,
        private ReconcileEstimateGenerationDocuments $sessions,
    ) {}

    /** @param array<int, string> $qualityFlags */
    public function handle(
        int $documentId,
        string $expectedSourceVersion,
        string $sourceVersion,
        array $qualityFlags,
    ): void {
        $session = $this->database->transaction(function () use ($documentId, $expectedSourceVersion, $sourceVersion, $qualityFlags): ?EstimateGenerationSession {
            $document = EstimateGenerationDocument::query()->with('session')->lockForUpdate()->find($documentId);
            if (! $document instanceof EstimateGenerationDocument
                || ! $document->session instanceof EstimateGenerationSession
                || (string) $document->source_version !== $expectedSourceVersion
                || in_array((string) $document->status, ['ready', 'ignored'], true)) {
                return null;
            }

            $document->forceFill([
                'status' => 'needs_review',
                'source_version' => $sourceVersion,
                'processing_stage' => 'completed',
                'progress_percent' => 100,
                'quality_score' => 0.0,
                'quality_level' => 'unusable',
                'quality_flags' => array_values(array_unique($qualityFlags)),
                'facts_summary' => [],
                ...($expectedSourceVersion !== $sourceVersion ? [
                    'units_finalized_source_version' => null,
                    'units_reconciled_source_version' => null,
                    'units_reconcile_claim_token' => null,
                    'units_reconcile_lease_expires_at' => null,
                ] : []),
                'ocr_finished_at' => now(),
            ])->save();

            return $document->session;
        }, 3);

        if ($session instanceof EstimateGenerationSession) {
            $this->sessions->reconcile($session);
        }
    }
}
