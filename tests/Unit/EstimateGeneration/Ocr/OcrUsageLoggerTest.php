<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrUsageLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class OcrUsageLoggerTest extends TestCase
{
    public function test_it_logs_usage_without_document_content_or_storage_path(): void
    {
        $document = (new EstimateGenerationDocument())->forceFill([
            'id' => 10,
            'session_id' => 20,
            'organization_id' => 30,
            'project_id' => 40,
            'filename' => 'private-scope.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-30/private-scope.pdf',
            'file_size_bytes' => 1234,
            'checksum_sha256' => hash('sha256', 'secret-content'),
            'ocr_attempts' => 1,
            'meta' => [
                'original_extension' => 'pdf',
            ],
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('[EstimateGeneration OCR] Recognition completed', Mockery::on(static function (array $context): bool {
                return $context['document_id'] === 10
                    && $context['session_id'] === 20
                    && $context['extension'] === 'pdf'
                    && $context['checksum_prefix'] === substr(hash('sha256', 'secret-content'), 0, 12)
                    && !array_key_exists('filename', $context)
                    && !array_key_exists('storage_path', $context)
                    && !array_key_exists('content', $context)
                    && !array_key_exists('api_key', $context);
            }));

        app(OcrUsageLogger::class)->completed(
            $document,
            new OcrRecognitionResult(
                provider: 'test',
                model: 'page',
                pages: [new OcrPageResult(pageNumber: 1, text: 'Общая площадь 1200 м2')],
            ),
            [],
            0.95,
            'good',
            120
        );
    }
}
