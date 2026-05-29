<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\ExtractedDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OcrDocumentProcessor
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly OcrPreflightService $preflightService,
        private readonly OcrClientInterface $ocrClient,
        private readonly DocumentProcessingStatusService $statusService,
        private readonly OcrQualityAnalyzer $qualityAnalyzer,
        private readonly ConstructionDocumentFactExtractor $factExtractor,
        private readonly DocumentFactMerger $factMerger,
        private readonly SpreadsheetDocumentExtractor $spreadsheetExtractor,
        private readonly PdfTextLayerExtractor $pdfTextLayerExtractor,
        private readonly OcrUsageLogger $usageLogger,
    ) {}

    public function process(EstimateGenerationDocument $document): EstimateGenerationDocument
    {
        $startedAt = microtime(true);

        try {
            $this->statusService->markProcessing($document, 'preflight', 10);
            $this->preflightService->validateForRecognition($document);
            $content = $this->documentContent($document);
            $pageCount = $this->preflightService->validatePdfPageCount($document, $content);

            if ($pageCount !== null && (int) $document->page_count !== $pageCount) {
                $document->forceFill(['page_count' => $pageCount])->save();
                $document->refresh();
            }

            $provider = $this->preflightService->isSpreadsheet($document)
                ? SpreadsheetDocumentExtractor::PROVIDER
                : (string) config('estimate-generation.ocr.provider', 'yandex_cloud_ocr');
            $this->usageLogger->started($document, $provider);

            $this->statusService->markProcessing($document, $this->recognitionStage($document), 35);
            $recognition = $this->recognize($document, $content, $pageCount);

            $this->statusService->markProcessing($document, 'normalization', 75);
            $quality = $this->qualityAnalyzer->analyze($recognition);
            $facts = $this->factExtractor->extract($recognition, (int) $document->id, $document->filename);
            $factsSummary = $this->factMerger->summarize($facts);
            $this->persistRecognition($document, $recognition, $facts, $factsSummary);
            $this->usageLogger->completed(
                $document,
                $recognition,
                $facts,
                $quality->score,
                $quality->level,
                $this->elapsedMs($startedAt)
            );

            if (in_array($quality->level, ['low', 'unusable'], true) || ($factsSummary['conflicts'] ?? []) !== []) {
                $flags = $quality->flags;

                if (($factsSummary['conflicts'] ?? []) !== []) {
                    $flags[] = 'conflicting_document_facts';
                }

                $this->statusService->markNeedsReview($document, $quality->score, array_values(array_unique($flags)), $factsSummary);
            } else {
                $this->statusService->markReady($document, $quality->score, $quality->level, $factsSummary);
            }

            return $document->refresh();
        } catch (OcrProviderException $exception) {
            $this->usageLogger->failed(
                $document,
                $exception->providerCode ?? 'ocr_provider_error',
                $exception->context,
                $this->elapsedMs($startedAt)
            );
            $this->statusService->markFailed(
                $document,
                $exception->providerCode ?? 'ocr_provider_error',
                $exception->messageKey,
                $exception->context,
            );

            return $document->refresh();
        } catch (Throwable $exception) {
            $this->usageLogger->failed($document, 'ocr_processing_error', [], $this->elapsedMs($startedAt));
            Log::error('[EstimateGeneration OCR] Document processing failed', [
                'document_id' => $document->id,
                'session_id' => $document->session_id,
                'error' => $exception->getMessage(),
            ]);

            $this->statusService->markFailed(
                $document,
                'ocr_processing_error',
                'estimate_generation.ocr_provider_error',
            );

            return $document->refresh();
        }
    }

    private function recognize(EstimateGenerationDocument $document, string $content, ?int $pageCount): OcrRecognitionResult
    {
        if ($this->preflightService->isSpreadsheet($document)) {
            return $this->spreadsheetExtractor->extract($document, $content);
        }

        if ($this->preflightService->isPdf($document)) {
            $textLayerRecognition = $this->pdfTextLayerExtractor->extract($content, $document->filename);

            if ($textLayerRecognition instanceof OcrRecognitionResult) {
                return $textLayerRecognition;
            }

            if ($pageCount === null) {
                throw new OcrProviderException(
                    'estimate_generation.ocr_pdf_page_count_unavailable',
                    providerCode: 'pdf_page_count_unavailable',
                );
            }

            if ($pageCount > 1) {
                throw new OcrProviderException(
                    'estimate_generation.ocr_pdf_text_layer_missing',
                    providerCode: 'pdf_text_layer_missing',
                    context: ['page_count' => $pageCount],
                );
            }

            $this->statusService->markProcessing($document, 'ocr_request', 45);
        }

        return $this->ocrClient->recognize(new OcrDocumentInput(
            content: $content,
            mimeType: $document->mime_type ?? 'application/octet-stream',
            filename: $document->filename,
            pageCount: $pageCount,
        ));
    }

    private function recognitionStage(EstimateGenerationDocument $document): string
    {
        if ($this->preflightService->isSpreadsheet($document)) {
            return 'spreadsheet_extraction';
        }

        if ($this->preflightService->isPdf($document)) {
            return 'pdf_text_layer';
        }

        return 'ocr_request';
    }

    private function documentContent(EstimateGenerationDocument $document): string
    {
        $organization = $document->session?->organization;

        return $this->fileService->disk($organization)->get((string) $document->storage_path);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param array<int, ExtractedDocumentFact> $facts
     * @param array<string, mixed> $factsSummary
     */
    private function persistRecognition(
        EstimateGenerationDocument $document,
        OcrRecognitionResult $recognition,
        array $facts,
        array $factsSummary
    ): void
    {
        DB::transaction(function () use ($document, $recognition, $facts, $factsSummary): void {
            $document->facts()->delete();
            $document->pages()->delete();
            $pageIds = [];

            foreach ($recognition->pages as $page) {
                $pageModel = EstimateGenerationDocumentPage::create([
                    'document_id' => $document->id,
                    'organization_id' => $document->organization_id,
                    'project_id' => $document->project_id,
                    'session_id' => $document->session_id,
                    'page_number' => $page->pageNumber,
                    'width' => $page->width,
                    'height' => $page->height,
                    'rotation' => $page->rotation,
                    'language_codes' => $page->languageCodes,
                    'text' => $page->text,
                    'text_hash' => $page->text !== '' ? hash('sha256', $page->text) : null,
                    'confidence' => $page->confidence,
                    'normalized_payload' => [
                        'blocks' => $page->blocks,
                    ],
                    'quality_flags' => [],
                ]);

                $pageIds[$page->pageNumber] = $pageModel->id;
            }

            foreach ($facts as $fact) {
                $pageNumber = (int) ($fact->sourceRef['page_number'] ?? 0);

                EstimateGenerationDocumentFact::create([
                    'document_id' => $document->id,
                    'page_id' => $pageIds[$pageNumber] ?? null,
                    'organization_id' => $document->organization_id,
                    'project_id' => $document->project_id,
                    'session_id' => $document->session_id,
                    'fact_type' => $fact->factType,
                    'scope_key' => $fact->scopeKey,
                    'label' => $fact->label,
                    'value_text' => $fact->valueText,
                    'value_number' => $fact->valueNumber,
                    'unit' => $fact->unit,
                    'confidence' => $fact->confidence,
                    'source_ref' => $fact->sourceRef,
                    'normalized_payload' => $fact->normalizedPayload,
                ]);
            }

            $document->forceFill([
                'extracted_text' => $recognition->text(),
                'structured_payload' => $recognition->toArray(),
                'facts_summary' => $factsSummary,
                'page_count' => count($recognition->pages),
                'processed_page_count' => count($recognition->pages),
                'ocr_provider' => $recognition->provider,
                'ocr_model' => $recognition->model,
            ])->save();
        });
    }
}
