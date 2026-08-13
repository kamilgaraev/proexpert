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
        self::assertLessThan(strpos($source, 'assertCalculatedPricesFinalized'), strpos($source, 'syncFromDraft'));
        self::assertLessThan(strpos($source, 'generationCompleted'), strpos($source, 'assertCalculatedPricesFinalized'));
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

    #[Test]
    public function document_retry_lineage_is_propagated_before_units_are_dispatched(): void
    {
        $dispatcher = $this->source('Application/Sessions/LaravelEstimateGenerationRetryDispatcher.php');
        $creator = $this->source('Application/Documents/CreateDocumentProcessingUnits.php');

        self::assertStringContainsString("'processing_attempt_id' => \$attemptId", $dispatcher);
        self::assertStringContainsString('$this->resetter->handle(', $dispatcher);
        self::assertStringContainsString("'attempt_count' => 0", $this->source('Application/Documents/ResetDocumentProcessingUnitsForAttempt.php'));
        self::assertStringContainsString("'processing_attempt_id' => \$processingAttemptId", $creator);
        self::assertLessThan(
            strpos($dispatcher, 'ProcessEstimateGenerationDocumentJob::dispatch('),
            strpos($dispatcher, '$this->resetter->handle('),
        );
    }

    #[Test]
    public function ordinary_session_resource_uses_readiness_geometry_evidence_for_workflow_gating(): void
    {
        $resource = $this->source('Http/Resources/EstimateGenerationSessionResource.php');

        self::assertStringContainsString("\$readiness->metrics['drawing_elements']", $resource);
        self::assertStringContainsString("'drawing_elements' => \$drawingElements", $resource);
        self::assertLessThan(
            strpos($resource, 'documentsSummary('),
            strpos($resource, '$readiness = app(EstimatorReadinessService::class)->evaluate($session)'),
        );
    }

    #[Test]
    public function geometry_confirmation_starts_generation_through_the_quota_aware_workflow(): void
    {
        $source = $this->source('Application/Geometry/ConfirmBuildingGeometry.php');

        self::assertStringContainsString('AdvanceEstimateGeneration $advance', $source);
        self::assertStringContainsString('$this->advance->generationStarted(', $source);
        self::assertStringNotContainsString("\$session->forceFill(['state_version'", $source);
        self::assertLessThan(strpos($source, '$this->outbox->append('), strpos($source, '$this->advance->generationStarted('));
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/'.$relative);
        self::assertIsString($source);

        return $source;
    }
}
