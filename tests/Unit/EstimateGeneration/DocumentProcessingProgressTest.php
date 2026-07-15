<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\DocumentProcessingProgress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentProcessingProgressTest extends TestCase
{
    #[Test]
    public function terminal_documents_advance_session_progress_while_processing_continues(): void
    {
        self::assertSame(9, DocumentProcessingProgress::fromSummary([
            'total' => 8,
            'ready' => 0,
            'action_required' => 1,
            'ignored' => 0,
        ], 5));
    }

    #[Test]
    public function progress_never_moves_backwards(): void
    {
        self::assertSame(20, DocumentProcessingProgress::fromSummary([
            'total' => 8,
            'ready' => 1,
            'action_required' => 0,
            'ignored' => 0,
        ], 20));
    }
}
