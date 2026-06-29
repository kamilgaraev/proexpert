<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class DocumentGenerationReadinessServiceTest extends TestCase
{
    public function test_ready_document_without_understanding_role_blocks_generation(): void
    {
        $document = new EstimateGenerationDocument();
        $document->forceFill([
            'id' => 6,
            'filename' => 'unknown-upload.pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'good',
            'quality_score' => 0.91,
            'quality_flags' => [],
            'facts_summary' => [
                'total_area_m2' => 128.0,
            ],
        ]);

        $summary = (new DocumentGenerationReadinessService())->summary(new Collection([$document]));

        self::assertSame(1, $summary['missing_understanding_count']);
        self::assertSame(1, $summary['action_required_count']);
        self::assertFalse($summary['can_generate']);
        self::assertContains('document_understanding_missing', $summary['problem_flags']);
        self::assertTrue($summary['items'][0]['missing_document_understanding']);
        self::assertTrue($summary['items'][0]['is_action_required']);
    }

    public function test_ready_document_that_requires_understanding_review_blocks_generation(): void
    {
        $document = new EstimateGenerationDocument();
        $document->forceFill([
            'id' => 7,
            'filename' => 'Планировка.jpg',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'medium',
            'quality_score' => 0.76,
            'quality_flags' => [],
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => 'needs_review',
                    'extracted_capabilities' => [
                        'requires_manual_review' => true,
                    ],
                ],
            ],
        ]);

        $summary = (new DocumentGenerationReadinessService())->summary(new Collection([$document]));

        self::assertSame(1, $summary['understanding_review_count']);
        self::assertSame(1, $summary['action_required_count']);
        self::assertFalse($summary['can_generate']);
        self::assertContains('document_understanding_requires_review', $summary['problem_flags']);
        self::assertTrue($summary['items'][0]['requires_document_review']);
        self::assertTrue($summary['items'][0]['is_action_required']);
    }

    public function test_ready_low_quality_document_blocks_generation(): void
    {
        $document = new EstimateGenerationDocument();
        $document->forceFill([
            'id' => 8,
            'filename' => 'Размытый скан.pdf',
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_level' => 'unusable',
            'quality_score' => 0.18,
            'quality_flags' => ['ocr_text_too_short'],
            'facts_summary' => [],
        ]);

        $summary = (new DocumentGenerationReadinessService())->summary(new Collection([$document]));

        self::assertSame(1, $summary['low_quality_count']);
        self::assertSame(1, $summary['action_required_count']);
        self::assertFalse($summary['can_generate']);
        self::assertContains('document_low_quality', $summary['problem_flags']);
        self::assertTrue($summary['items'][0]['has_low_quality']);
        self::assertTrue($summary['items'][0]['is_action_required']);
    }
}
