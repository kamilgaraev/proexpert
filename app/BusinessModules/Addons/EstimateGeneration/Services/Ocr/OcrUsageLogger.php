<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use Illuminate\Support\Facades\Log;

class OcrUsageLogger
{
    public function queued(EstimateGenerationDocument $document): void
    {
        Log::info('[EstimateGeneration OCR] Document queued', $this->baseContext($document));
    }

    public function started(EstimateGenerationDocument $document, string $provider): void
    {
        Log::info('[EstimateGeneration OCR] Recognition started', [
            ...$this->baseContext($document),
            'provider' => $provider,
        ]);
    }

    /**
     * @param array<int, mixed> $facts
     */
    public function completed(
        EstimateGenerationDocument $document,
        OcrRecognitionResult $recognition,
        array $facts,
        float $qualityScore,
        string $qualityLevel,
        int $elapsedMs
    ): void {
        Log::info('[EstimateGeneration OCR] Recognition completed', [
            ...$this->baseContext($document),
            'provider' => $recognition->provider,
            'model' => $recognition->model,
            'pages_count' => count($recognition->pages),
            'facts_count' => count($facts),
            'quality_score' => $qualityScore,
            'quality_level' => $qualityLevel,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function failed(EstimateGenerationDocument $document, string $errorCode, array $context = [], ?int $elapsedMs = null): void
    {
        Log::warning('[EstimateGeneration OCR] Recognition failed', [
            ...$this->baseContext($document),
            'error_code' => $errorCode,
            'provider_code' => $context['provider_code'] ?? null,
            'status' => $context['status'] ?? null,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseContext(EstimateGenerationDocument $document): array
    {
        $extension = strtolower((string) ($document->meta['original_extension'] ?? pathinfo($document->filename, PATHINFO_EXTENSION)));

        return [
            'document_id' => $document->id,
            'session_id' => $document->session_id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'mime_type' => $document->mime_type,
            'extension' => $extension,
            'file_size_bytes' => $document->file_size_bytes,
            'checksum_prefix' => $document->checksum_sha256 ? substr((string) $document->checksum_sha256, 0, 12) : null,
            'attempts' => $document->ocr_attempts,
        ];
    }
}
