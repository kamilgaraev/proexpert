<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingOutcomeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentProcessingOutcomeResolverTest extends TestCase
{
    #[Test]
    public function all_ready_pages_report_honest_completed_outcome_even_without_text(): void
    {
        $outcome = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'ready'), $this->page(2, 'ready')],
            [$this->unit(1, 'completed', 1), $this->unit(2, 'completed', 1)],
        );

        self::assertSame('ready', $outcome->documentStatus);
        self::assertSame('ready', $outcome->type);
        self::assertSame(2, $outcome->processedPages);
        self::assertSame([
            'included' => 2,
            'ready' => 2,
            'needs_user_action' => 0,
            'system_failed' => 0,
            'processing' => 0,
            'excluded' => 0,
        ], $outcome->counts);
        self::assertNull($outcome->errorCode);
    }

    #[Test]
    public function zero_of_twenty_two_terminal_pages_is_a_document_system_failure_not_user_review(): void
    {
        $pages = [];
        $units = [];
        foreach (range(1, 22) as $index) {
            $pages[] = $this->page($index, 'failed');
            $units[] = $this->unit($index, 'failed', 0, 'terminal');
        }

        $outcome = (new DocumentProcessingOutcomeResolver)->resolve($pages, $units);

        self::assertSame('failed', $outcome->documentStatus);
        self::assertSame('system_failure', $outcome->type);
        self::assertSame(0, $outcome->processedPages);
        self::assertSame(22, $outcome->counts['system_failed']);
        self::assertSame(0, $outcome->counts['needs_user_action']);
        self::assertSame('document_processing_system_failed', $outcome->errorCode);
        self::assertSame('estimate_generation.document_processing_system_failed', $outcome->errorMessageKey);
    }

    #[Test]
    public function partial_success_remains_system_failure_with_usable_page_count(): void
    {
        $outcome = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'ready'), $this->page(2, 'failed')],
            [$this->unit(1, 'completed', 1), $this->unit(2, 'failed', 0, 'terminal')],
        );

        self::assertSame('failed', $outcome->documentStatus);
        self::assertSame(1, $outcome->processedPages);
        self::assertSame(1, $outcome->counts['ready']);
        self::assertSame(1, $outcome->counts['system_failed']);
    }

    #[Test]
    public function user_action_and_processing_are_distinct_from_system_failure(): void
    {
        $review = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'needs_review')],
            [$this->unit(1, 'failed', 0, 'user_action_required')],
        );
        $processing = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'processing')],
            [$this->unit(1, 'running', 0)],
        );

        self::assertSame('needs_review', $review->documentStatus);
        self::assertSame('user_action_required', $review->type);
        self::assertSame(1, $review->counts['needs_user_action']);
        self::assertNull($review->errorCode);
        self::assertSame('processing', $processing->documentStatus);
        self::assertSame('processing', $processing->type);
        self::assertSame(1, $processing->counts['processing']);
    }

    #[Test]
    public function exhausted_recoverable_failure_remains_explicitly_retryable(): void
    {
        $outcome = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'failed')],
            [$this->unit(1, 'failed', 0, 'recoverable')],
        );

        self::assertSame('temporary_failure', $outcome->type);
        self::assertSame('failed', $outcome->documentStatus);
        self::assertTrue($outcome->retryAllowed);
        self::assertSame('document_processing_temporarily_unavailable', $outcome->errorCode);
    }

    /** @return array{processing_unit_id: int, status: string} */
    private function page(int $unitId, string $status): array
    {
        return ['processing_unit_id' => $unitId, 'status' => $status];
    }

    /** @return array{id: int, status: string, output_count: int, metadata: array<string, string>} */
    private function unit(int $id, string $status, int $outputCount, ?string $category = null): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'output_count' => $outputCount,
            'metadata' => $category === null ? [] : ['failure_category' => $category],
        ];
    }
}
