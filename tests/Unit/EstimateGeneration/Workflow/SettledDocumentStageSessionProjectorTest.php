<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SettledDocumentStageSessionProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettledDocumentStageSessionProjectorTest extends TestCase
{
    #[Test]
    public function stopped_partial_documents_replace_stale_processing_header_without_mutating_execution_counts(): void
    {
        $projected = (new SettledDocumentStageSessionProjector)->project([
            'status' => 'processing_documents',
            'processing_stage' => 'processing_documents',
            'processing_progress' => 5,
        ], [
            'total' => 1,
            'pending' => 0,
            'action_required' => 1,
            'pages' => 22,
            'processed_pages' => 2,
        ]);

        self::assertSame('input_review_required', $projected['status']);
        self::assertSame('input_review_required', $projected['processing_stage']);
        self::assertSame(35, $projected['processing_progress']);
    }

    #[Test]
    public function genuinely_pending_documents_keep_the_processing_header(): void
    {
        $session = [
            'status' => 'processing_documents',
            'processing_stage' => 'processing_documents',
            'processing_progress' => 20,
        ];

        self::assertSame($session, (new SettledDocumentStageSessionProjector)->project($session, [
            'total' => 2,
            'pending' => 1,
            'action_required' => 1,
        ]));
    }
}
