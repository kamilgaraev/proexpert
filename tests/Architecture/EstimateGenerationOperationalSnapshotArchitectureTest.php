<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationOperationalSnapshotArchitectureTest extends TestCase
{
    #[Test]
    public function polling_has_one_lightweight_route_and_no_legacy_controller_reference(): void
    {
        $routes = $this->source('routes.php');

        self::assertSame(1, substr_count($routes, "Route::get('/{session}/snapshot'"));
        self::assertStringContainsString('EstimateGenerationSessionController::class', $routes);
        self::assertStringContainsString('EstimateGenerationActionController::class', $routes);
        self::assertStringNotContainsString('EstimateGenerationController::class', $routes);
        self::assertStringContainsString('authorize:estimate_generation.view,project,project', $routes);
    }

    #[Test]
    public function builder_uses_bounded_aggregate_queries_and_never_selects_private_payloads(): void
    {
        $source = $this->source('Application/Sessions/BuildSessionOperationalSnapshot.php');

        foreach (['count(', 'sum(', 'max(', 'organization_id', 'project_id', 'session_id'] as $needle) {
            self::assertStringContainsString($needle, strtolower($source));
        }
        foreach (['->get()', '->cursor()', 'output_payload', 'safe_context', 'price_snapshot', 'storage_path', 'extracted_text'] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
        self::assertLessThanOrEqual(14, substr_count($source, '->first()') + substr_count($source, '->value('));
        self::assertStringContainsString('REPEATABLE READ', $source);
        self::assertStringContainsString('READ ONLY', $source);
        self::assertStringContainsString('COALESCE(input_tokens, 0)', $source);
        self::assertStringContainsString('COUNT(cost_amount) > 0', $source);
        self::assertStringContainsString('usage_unavailable', $source);
        self::assertStringContainsString('actionRequiredSql()', $source);
        self::assertStringContainsString('facts_summary->', $this->source('Services/Quality/DocumentReadinessClassifier.php'));
        self::assertStringContainsString('outbox_max_updated_at', $source);
        self::assertStringContainsString('deliveries_max_updated_at', $source);
        foreach ([
            'pages_max_updated_at',
            'facts_max_updated_at',
            'drawings_max_updated_at',
            'quantities_max_updated_at',
            'scopes_max_updated_at',
            'edges_max_created_at',
            'feedback_max_updated_at',
            'audit_max_updated_at',
            'failure_events_max_sequence',
        ] as $watermark) {
            self::assertStringContainsString($watermark, $source);
        }
        self::assertStringNotContainsString('CURRENT_TIMESTAMP - lease_expires_at', $source);
        self::assertStringContainsString('public const QUERY_BUDGET = 11;', $source);
    }

    #[Test]
    public function append_only_watermarks_have_database_contracts(): void
    {
        $usage = $this->migration('2026_07_11_000400_create_estimate_generation_ai_usage_table.php');
        $evidence = $this->migration('2026_07_11_000300_create_estimate_generation_evidence_table.php');
        $failures = $this->migration('2026_07_11_000500_create_estimate_generation_failures_table.php');

        self::assertStringContainsString('eg_usage_immutable_guard', $usage);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $usage);
        self::assertStringContainsString('eg_evidence_edge_append_trg', $evidence);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $evidence);
        self::assertStringContainsString('eg_failure_events_append_only_guard', $failures);
        self::assertStringContainsString('BEFORE UPDATE OR DELETE', $failures);

        $reviewGuard = $this->migration('2026_07_11_000900_guard_review_summary_source_version.php');
        self::assertStringContainsString('eg_review_summary_source_guard_trg', $reviewGuard);
        self::assertStringContainsString('source_version IS DISTINCT FROM content_version', $reviewGuard);
        self::assertStringContainsString('classifier_version <> 1', $reviewGuard);
    }

    #[Test]
    public function session_and_action_controllers_have_disjoint_public_ownership(): void
    {
        $session = $this->source('Http/Controllers/EstimateGenerationSessionController.php');
        $action = $this->source('Http/Controllers/EstimateGenerationActionController.php');

        foreach (['index', 'store', 'show', 'snapshot'] as $method) {
            self::assertStringContainsString('function '.$method.'(', $session);
            self::assertStringNotContainsString('function '.$method.'(', $action);
        }
        foreach (['analyze', 'generate', 'retry', 'cancel', 'archive', 'apply', 'rebuildSection'] as $method) {
            self::assertStringContainsString('function '.$method.'(', $action);
            self::assertStringNotContainsString('function '.$method.'(', $session);
        }
        self::assertFileDoesNotExist($this->root().'/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationController.php');
    }

    private function source(string $relative): string
    {
        $source = file_get_contents($this->root().'/app/BusinessModules/Addons/EstimateGeneration/'.$relative);
        self::assertIsString($source);

        return $source;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function migration(string $file): string
    {
        $source = file_get_contents($this->root().'/app/BusinessModules/Addons/EstimateGeneration/migrations/'.$file);
        self::assertIsString($source);

        return $source;
    }
}
