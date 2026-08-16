<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingOutcomeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentProcessingOutcomeResolverTest extends TestCase
{
    public function test_cancelled_terminal_unit_overrides_a_stale_queued_page_projection(): void
    {
        $outcome = (new DocumentProcessingOutcomeResolver)->resolve(
            [['processing_unit_id' => 1, 'status' => 'queued', 'quality_flags' => []]],
            [[
                'id' => 1,
                'status' => 'superseded',
                'output_count' => 0,
                'failure_code' => null,
                'metadata' => [
                    'processing_control_status' => 'cancelled',
                    'processing_control_reason' => 'operator_stop',
                ],
            ]],
        );

        self::assertSame('cancelled', $outcome->type);
        self::assertSame('needs_review', $outcome->documentStatus);
        self::assertSame(0, $outcome->counts['processing']);
        self::assertSame(1, $outcome->counts['cancelled']);
    }

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
            'terminal_system_failed' => 0,
            'breaker_stopped' => 0,
            'system_failed' => 0,
            'processing' => 0,
            'excluded' => 0,
            'cancelled' => 0,
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

        self::assertSame('needs_review', $outcome->documentStatus);
        self::assertSame(1, $outcome->processedPages);
        self::assertSame(1, $outcome->counts['ready']);
        self::assertSame(1, $outcome->counts['system_failed']);
    }

    #[Test]
    public function production_shaped_partial_failure_separates_executed_failures_from_breaker_stops(): void
    {
        $pages = [];
        $units = [];
        foreach (range(1, 22) as $index) {
            $ready = $index <= 2;
            $breaker = $index >= 6;
            $pages[] = $this->page($index, $ready ? 'ready' : 'failed');
            $units[] = $this->unit(
                $index,
                $ready ? 'completed' : 'failed',
                $ready ? 1 : 0,
                $ready ? null : 'terminal',
                $breaker ? 'breaker_stopped' : ($ready ? null : 'invalid_analysis_schema'),
            );
        }

        $outcome = (new DocumentProcessingOutcomeResolver)->resolve($pages, $units);
        $contract = $outcome->toArray();

        self::assertSame(2, $outcome->processedPages);
        self::assertSame(3, $outcome->counts['terminal_system_failed']);
        self::assertSame(17, $outcome->counts['breaker_stopped']);
        self::assertSame(20, $outcome->counts['system_failed']);
        self::assertSame(100, $contract['execution_progress_percent']);
        self::assertSame('blocked', $contract['readiness']);
        self::assertFalse($contract['is_ready']);
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
    public function completed_primary_with_bounded_targeted_limitation_is_review_not_ready(): void
    {
        $outcome = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'needs_review')],
            [$this->unit(1, 'completed', 1)],
        );

        self::assertSame('user_action_required', $outcome->type);
        self::assertSame('needs_review', $outcome->documentStatus);
        self::assertSame(1, $outcome->counts['needs_user_action']);
        self::assertSame(1, $outcome->counts['ready']);
        self::assertSame(1, $outcome->processedPages);
        self::assertSame('review_required', $outcome->toArray()['readiness']);
    }

    #[Test]
    public function legacy_question_flags_and_quarantine_are_both_non_question_partial_states(): void
    {
        $questions = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'needs_review', ['ai_questions_pending'])],
            [$this->unit(1, 'completed', 1)],
        );
        $partial = (new DocumentProcessingOutcomeResolver)->resolve(
            [$this->page(1, 'needs_review', ['ai_partial_result'])],
            [$this->unit(1, 'completed', 1)],
        );

        self::assertSame('partial', $questions->toArray()['state']);
        self::assertSame('partial', $partial->toArray()['state']);
    }

    #[Test]
    public function production_partial_result_preserves_thirteen_completed_outputs_and_terminal_counts(): void
    {
        $pages = [];
        $units = [];
        foreach (range(1, 22) as $index) {
            $completed = $index <= 14 && $index !== 11;
            $needsReview = $completed && $index >= 3;
            $breakerStopped = in_array($index, [15, 16, 18, 19, 20, 21, 22], true);
            $pages[] = $this->page(
                $index,
                $completed ? ($needsReview ? 'needs_review' : 'ready') : 'failed',
            );
            $units[] = $this->unit(
                $index,
                $completed ? 'completed' : 'failed',
                $completed ? 1 : 0,
                $completed ? null : 'terminal',
                $breakerStopped ? 'breaker_stopped' : ($completed ? null : 'vision_provider_response_invalid'),
            );
        }

        $outcome = (new DocumentProcessingOutcomeResolver)->resolve($pages, $units);

        self::assertSame('system_failure', $outcome->type);
        self::assertSame('partial', $outcome->toArray()['state']);
        self::assertSame('needs_review', $outcome->documentStatus);
        self::assertSame(13, $outcome->processedPages);
        self::assertSame(13, $outcome->counts['ready']);
        self::assertSame(11, $outcome->counts['needs_user_action']);
        self::assertSame(2, $outcome->counts['terminal_system_failed']);
        self::assertSame(7, $outcome->counts['breaker_stopped']);
        self::assertSame(9, $outcome->counts['system_failed']);
        self::assertSame(100, $outcome->toArray()['execution_progress_percent']);
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

    /** @param list<string> $qualityFlags @return array{processing_unit_id: int, status: string, quality_flags:list<string>} */
    private function page(int $unitId, string $status, array $qualityFlags = []): array
    {
        return ['processing_unit_id' => $unitId, 'status' => $status, 'quality_flags' => $qualityFlags];
    }

    /** @return array{id: int, status: string, output_count: int, metadata: array<string, string>} */
    private function unit(int $id, string $status, int $outputCount, ?string $category = null, ?string $failureCode = null): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'output_count' => $outputCount,
            'failure_code' => $failureCode,
            'metadata' => $category === null ? [] : ['failure_category' => $category],
        ];
    }
}
