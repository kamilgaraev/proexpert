<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationMutationAtomicityTest extends TestCase
{
    #[Test]
    public function normative_selection_locks_and_checks_before_writes(): void
    {
        $source = $this->source('Application/Review/SelectNormativeCandidate.php');

        self::assertStringContainsString('DB::transaction', $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertLessThan(strpos($source, '$this->selection->select'), strpos($source, '$this->policy->review'));
    }

    #[Test]
    public function feedback_checks_version_before_creating_any_evidence(): void
    {
        $source = $this->source('Application/Review/RecordEstimateGenerationFeedback.php');

        self::assertStringContainsString('DB::transaction', $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertLessThan(strpos($source, 'EstimateGenerationFeedback::query()->create'), strpos($source, '$this->policy->review'));
        self::assertLessThan(strpos($source, 'recordFeedbackDecision'), strpos($source, 'EstimateGenerationFeedback::query()->create'));
    }

    #[Test]
    public function generation_publication_is_owner_checked_and_atomic(): void
    {
        $source = $this->source('Pipeline/PublishValidatedDraft.php');
        $store = $this->source('Pipeline/EloquentPipelineCheckpointStore.php');

        self::assertStringContainsString('->transaction(', $store);
        self::assertStringContainsString('$this->completionHook->beforeComplete(', $store);
        self::assertLessThan(strpos($source, 'syncFromDraft'), strpos($source, '->lockForUpdate()'));
        self::assertLessThan(strpos($source, 'syncFromDraft'), strpos($source, 'hash_equals('));
        self::assertLessThan(strpos($source, 'generationCompleted'), strpos($source, 'syncFromDraft'));
    }

    #[Test]
    public function manual_ignore_invalidates_session_before_aggregate_reconciliation(): void
    {
        $source = $this->source('Application/Documents/IgnoreEstimateGenerationDocument.php');

        self::assertStringContainsString('DB::transaction', $source);
        self::assertLessThan(strpos($source, '$lockedDocument->forceFill'), strpos($source, '$this->policy->documents'));
        self::assertLessThan(strpos($source, '$this->reconciler->reconcile'), strpos($source, '$this->reconciler->changed'));
    }

    #[Test]
    public function retry_scopes_and_locks_session_while_dispatchers_use_after_commit(): void
    {
        $repository = $this->source('Application/Sessions/EloquentRetryableEstimateGenerationSessionRepository.php');
        $dispatcher = $this->source('Application/Sessions/LaravelEstimateGenerationRetryDispatcher.php');

        self::assertStringContainsString('DB::transaction', $repository);
        self::assertStringContainsString("->where('organization_id', \$organizationId)", $repository);
        self::assertStringContainsString("->where('project_id', \$projectId)", $repository);
        self::assertStringContainsString('->lockForUpdate()', $repository);
        self::assertStringContainsString('->firstOrFail()', $repository);
        self::assertSame(2, substr_count($dispatcher, '->afterCommit()'));
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/'.$relative);
        self::assertIsString($source);

        return $source;
    }
}
