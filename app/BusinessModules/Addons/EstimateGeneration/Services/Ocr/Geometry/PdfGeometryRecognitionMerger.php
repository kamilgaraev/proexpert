<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

final class PdfGeometryRecognitionMerger
{
    public function merge(OcrRecognitionResult $recognition, ?PdfGeometryExtractionResult $geometry): OcrRecognitionResult
    {
        if (! $geometry instanceof PdfGeometryExtractionResult || $geometry->pages === []) {
            return $recognition;
        }

        $pages = [];

        foreach ($recognition->pages as $page) {
            $geometryPage = $geometry->pageByNumber($page->pageNumber);

            if (! $geometryPage instanceof PdfGeometryPageData) {
                $pages[] = $page;
                continue;
            }

            $pages[] = new OcrPageResult(
                pageNumber: $page->pageNumber,
                text: $page->text,
                blocks: $page->blocks !== [] ? $page->blocks : $geometryPage->textBlocks,
                width: $page->width ?? $geometryPage->width,
                height: $page->height ?? $geometryPage->height,
                rotation: $page->rotation ?? $geometryPage->rotation,
                confidence: $page->confidence,
                languageCodes: $page->languageCodes,
                rawPayload: [
                    ...$page->rawPayload,
                    'geometry' => $geometryPage->toArray(),
                ],
            );
        }

        return new OcrRecognitionResult(
            provider: $recognition->provider,
            model: $recognition->model,
            pages: $pages,
            rawPayload: $recognition->rawPayload,
            metadata: [
                ...$recognition->metadata,
                'geometry_available' => true,
                'geometry_provider' => $geometry->provider,
                'geometry_model' => $geometry->model,
            ],
        );
    }

    public function fromGeometry(PdfGeometryExtractionResult $geometry, ?string $filename = null): OcrRecognitionResult
    {
        return new OcrRecognitionResult(
            provider: 'pdf_geometry',
            model: 'pymupdf_geometry_v1',
            pages: array_map(
                static fn (PdfGeometryPageData $page): OcrPageResult => new OcrPageResult(
                    pageNumber: $page->pageNumber,
                    text: $page->text(),
                    blocks: $page->textBlocks,
                    width: $page->width,
                    height: $page->height,
                    rotation: $page->rotation,
                    confidence: $page->pageRole !== 'empty' ? 0.55 : 0.2,
                    languageCodes: [],
                    rawPayload: [
                        'source' => 'pdf_geometry',
                        'geometry' => $page->toArray(),
                    ],
                ),
                $geometry->pages
            ),
            rawPayload: $geometry->toArray(),
            metadata: [
                'mime_type' => 'application/pdf',
                'filename' => $filename,
                'source' => 'pdf_geometry',
                'geometry_available' => true,
                'geometry_provider' => $geometry->provider,
                'geometry_model' => $geometry->model,
            ],
        );
    }
}
