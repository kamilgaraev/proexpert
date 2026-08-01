<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisOperationIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisRoutingResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetRole;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetRoleClassification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SheetAnalysisRoutingContractTest extends TestCase
{
    #[Test]
    public function targeted_operation_identity_is_stable_across_unit_lease_retries_and_changes_for_a_new_target(): void
    {
        $routing = ['role' => 'plan', 'reanalysis_reason' => 'sheet_role_insufficient_evidence'];

        $first = SheetAnalysisOperationIdentity::targeted(4, 5, 6, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), $routing);
        $retry = SheetAnalysisOperationIdentity::targeted(4, 5, 6, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), $routing);
        $changedTarget = SheetAnalysisOperationIdentity::targeted(4, 5, 6, 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), ['role' => 'section', 'reanalysis_reason' => 'sheet_role_conflict']);

        self::assertSame($first, $retry);
        self::assertNotSame($first, $changedTarget);
    }

    #[Test]
    public function routing_payload_persists_the_review_gate_and_role_inference(): void
    {
        $result = new SheetAnalysisRoutingResult(
            new SheetRoleClassification(SheetRole::Plan, 'plan', 1.0, 'provider_sheet_role', 'sheet_role_insufficient_evidence'),
            64,
            96,
            2048,
        );

        self::assertSame([
            'role' => 'plan',
            'source_role' => 'plan',
            'confidence' => 1.0,
            'inference_reason' => 'provider_sheet_role',
            'targeted_reanalysis' => true,
            'needs_review' => true,
            'reanalysis_reason' => 'sheet_role_insufficient_evidence',
            'max_facts' => 64,
            'max_elements' => 96,
            'max_output_tokens' => 2048,
        ], $result->toArray());
    }

    #[Test]
    public function durable_operation_contract_fences_a_wire_attempt_and_persists_recoverable_final_routing(): void
    {
        $root = dirname(__DIR__, 3);
        $journal = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/Understanding/SheetAnalysisOperationJournal.php');
        $migration = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000300_create_estimate_generation_sheet_analysis_operations.php');

        self::assertStringContainsString("'completed' && is_array(\$stored->analysis_payload)", $journal);
        self::assertStringContainsString("->where('lease_token', \$scope->claimToken)", $journal);
        self::assertStringContainsString("'final_routing' => \$routing", $journal);
        self::assertStringContainsString('eg_sheet_analysis_scope_kind_uq', $migration);
        self::assertStringContainsString('public $withinTransaction = false', $migration);
        self::assertStringContainsString('assertNoDuplicateAuditTransitions', $migration);
        self::assertStringContainsString('ensureConcurrentIndex', $migration);
        self::assertStringContainsString('eg_sheet_analysis_audit_transition_uq', $migration);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS eg_sheet_analysis_audit_transition_uq', $migration);
        self::assertStringContainsString("'sheet_targeted_reanalysis_transition'", $journal);
        self::assertStringContainsString("'attempt' => (string) \$operation->attempt_count", $journal);
        self::assertStringContainsString('DB::transaction(function () use ($operationId, $kind, $scope, $status, $reason, $attributes)', $journal);
        self::assertStringNotContainsString('audit_recorded_at', $journal);
        self::assertStringContainsString('private const LEASE_SECONDS = 1860', $journal);
        $processor = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProductionDocumentUnitProcessor.php');
        self::assertStringContainsString("\$targetedRouting['outcome'] = 'needs_review'", $processor);
        self::assertStringContainsString("'sheet_analysis_routing' => \$routingPayload", $processor);
    }

    #[Test]
    public function document_vision_runtime_cannot_outlive_its_journal_or_unit_lease(): void
    {
        $root = dirname(__DIR__, 3);
        $provider = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php');
        $journal = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/Understanding/SheetAnalysisOperationJournal.php');
        $unit = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProcessDocumentUnit.php');

        self::assertStringContainsString('DOCUMENT_OPERATION_MAX_SECONDS = 1800', $provider);
        self::assertStringContainsString('boundedDocumentAttemptTimeout', $provider);
        self::assertStringContainsString('retryDelayMilliseconds', $provider);
        self::assertStringContainsString('LEASE_SECONDS = 1860', $journal);
        self::assertStringContainsString('LEASE_SECONDS = 2100', $unit);
    }
}
