<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class RecoverStalledEstimateGenerationDocuments
{
    public function handle(int $minimumAgeSeconds = 120, int $limit = 100): int
    {
        $documents = EstimateGenerationDocument::query()
            ->with('session')
            ->where('status', 'queued')
            ->where('updated_at', '<=', now()->subSeconds(max(30, $minimumAgeSeconds)))
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $dispatched = 0;

        foreach ($documents as $document) {
            $session = $document->session;
            if (! $session instanceof EstimateGenerationSession) {
                continue;
            }

            try {
                $sourceVersion = DocumentSourceVersion::fromDocument($document);
            } catch (RuntimeException) {
                continue;
            }

            ProcessEstimateGenerationDocumentJob::dispatch(
                (int) $document->getKey(),
                FailureExecutionSnapshot::capture(
                    $session,
                    'document_manifest_recovery',
                    documentId: (int) $document->getKey(),
                    sourceVersion: $sourceVersion,
                ),
            )
                ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationDocumentJob::RECOVERY_QUEUE);

            $document->forceFill([
                'meta' => [
                    ...(is_array($document->meta) ? $document->meta : []),
                    'recovery_dispatched_at' => now()->toISOString(),
                ],
            ])->saveQuietly();
            $dispatched++;
        }

        if ($dispatched > 0) {
            Log::info('[EstimateGeneration] Recovered stalled document jobs', [
                'count' => $dispatched,
            ]);
        }

        return $dispatched;
    }
}
