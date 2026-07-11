<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;

final readonly class OcrDocumentUnitProcessor implements DocumentUnitProcessor
{
    public function __construct(
        private DocumentUnitContentReader $reader,
        private OcrClientInterface $ocr,
    ) {}

    public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
    {
        if ($context->type === DocumentUnitType::CadDrawing) {
            throw new DocumentUnitProcessingException('cad_geometry_processor_required');
        }

        $locator = $context->locator;
        if (in_array($context->type, [DocumentUnitType::PdfPage, DocumentUnitType::SpreadsheetSheet], true)
            && ! is_string($locator['artifact_path'] ?? null)) {
            throw new DocumentUnitProcessingException('unit_artifact_manifest_required');
        }

        $stream = $this->reader->open($context);

        try {
            $content = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if (! is_string($content) || $content === '') {
            throw new DocumentUnitProcessingException('unit_content_empty');
        }

        if (($locator['content_type'] ?? null) === 'text/plain') {
            return new DocumentUnitOutput(
                version: substr(hash('sha256', $content), 0, 64),
                text: $content,
                confidence: 1.0,
                normalizedPayload: ['source' => 'source_manifest'],
                unitType: $context->type,
                unitIndex: $context->index,
                sourceVersion: $context->sourceVersion,
            );
        }

        $correlationId = AiOperationContext::deterministicId(implode('|', [
            'unit', $context->sessionId, $context->documentId, $context->unitId, $context->sourceVersion,
            $context->claimToken, $context->unitAttemptCount,
        ]));
        $recognition = $this->ocr->recognize(new OcrDocumentInput(
            content: $content,
            mimeType: $context->mimeType,
            filename: $context->filename,
            pageCount: 1,
            operationContext: new AiOperationContext(
                correlationId: $correlationId,
                attemptId: $correlationId,
                organizationId: $context->organizationId,
                projectId: $context->projectId,
                sessionId: $context->sessionId,
                stage: 'understand_documents',
                operation: 'ocr',
                attemptOrdinal: 1,
                documentId: $context->documentId,
                unitId: $context->unitId,
            ),
        ));
        $page = $recognition->pages[0] ?? null;

        if ($page === null) {
            throw new DocumentUnitProcessingException('unit_recognition_empty');
        }

        return new DocumentUnitOutput(
            version: substr(hash('sha256', json_encode($page->toArray(), JSON_THROW_ON_ERROR)), 0, 64),
            text: $page->text,
            confidence: $page->confidence,
            normalizedPayload: ['blocks' => $page->blocks, 'provider' => $recognition->provider, 'model' => $recognition->model],
            width: $page->width,
            height: $page->height,
            rotation: $page->rotation,
            unitType: $context->type,
            unitIndex: $context->index,
            sourceVersion: $context->sourceVersion,
        );
    }
}
