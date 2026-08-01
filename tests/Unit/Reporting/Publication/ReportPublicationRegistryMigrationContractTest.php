<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ReportPublicationRegistryMigrationContractTest extends TestCase
{
    public function test_migration_declares_append_only_publication_and_feature_invariants(): void
    {
        $source = file_get_contents($this->migrationPath());
        self::assertIsString($source);

        self::assertStringContainsString("Schema::create('report_publications'", $source);
        self::assertStringContainsString("Schema::create('report_publication_events'", $source);
        self::assertStringContainsString("Schema::create('report_publication_features'", $source);
        self::assertStringContainsString("Schema::create('report_publication_outbox'", $source);
        self::assertStringContainsString("timestampTz('published_at', 6)", $source);
        self::assertStringContainsString('report_publications_one_active_code', $source);
        self::assertStringContainsString("WHERE status = 'published'", $source);
        self::assertStringContainsString("string('published_by', 128)", $source);
        self::assertStringContainsString("string('disabled_by', 128)->nullable()", $source);
        self::assertStringContainsString('report_publication_events_transition_unique', $source);
        self::assertStringContainsString('report_publication_reject_mutation', $source);
        self::assertStringContainsString("OLD.status <> 'published'", $source);
        self::assertStringContainsString("NEW.status <> 'disabled'", $source);
        self::assertStringContainsString('report_publication_require_event', $source);
        self::assertStringContainsString('DEFERRABLE INITIALLY DEFERRED', $source);
        self::assertStringContainsString('report_publication_events_append_only', $source);
        self::assertStringContainsString('report_publication_event_insert_guard', $source);
        self::assertStringContainsString('report_publication_append_transition_artifacts', $source);
        self::assertStringContainsString('report_publication_timestamp_not_monotonic', $source);
        self::assertStringContainsString('report_publication_feature_transition_required', $source);
        self::assertStringContainsString('report_publication_feature_required', $source);
        self::assertStringContainsString('report_publication_feature_delete_forbidden', $source);
        self::assertStringContainsString('report_publication_append_feature_outbox', $source);
        self::assertStringContainsString('report_publication_feature_binding_guard', $source);
        self::assertStringContainsString("NEW.mode = 'disabled'", $source);
        self::assertStringContainsString('WHERE id = NEW.publication_id', $source);
        self::assertStringContainsString('AND proof_sha256 = NEW.proof_sha256', $source);
        self::assertStringContainsString('FOR KEY SHARE NOWAIT', $source);
        self::assertStringContainsString('report_publication_outbox_guard', $source);
        self::assertStringContainsString('DROP FUNCTION IF EXISTS report_publication_require_event()', $source);
        self::assertStringNotContainsString('superseded', $source);

        $featureDrop = strpos($source, "Schema::dropIfExists('report_publication_features')");
        $allowlistFunctionDrop = strpos($source, 'DROP FUNCTION IF EXISTS report_publication_positive_unique_ids(jsonb)');
        self::assertIsInt($featureDrop);
        self::assertIsInt($allowlistFunctionDrop);
        self::assertLessThan($allowlistFunctionDrop, $featureDrop);
    }

    public function test_postgres_gate_is_opt_in_before_any_connection_and_covers_storage_races(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/Feature/Reporting/Publication/ReportPublicationRegistryPostgresTest.php');
        self::assertIsString($source);

        $guard = strpos($source, "getenv('REPORT_PUBLICATION_POSTGRES_TESTS') !== '1'");
        $connection = strpos($source, 'DB::connection()');
        self::assertIsInt($guard);
        self::assertIsInt($connection);
        self::assertLessThan($connection, $guard);
        self::assertStringContainsString("preg_match('/_(?:test|testing)$/D'", $source);
        self::assertStringContainsString('SELECT current_database() AS database_name', $source);
        self::assertStringContainsString('private bool $registryDatabaseInitialized = false', $source);
        self::assertStringContainsString('test_proof_and_events_are_append_only', $source);
        self::assertStringContainsString('test_only_one_active_publication_exists_per_code', $source);
        self::assertStringContainsString('test_state_transition_writes_matching_event_and_outbox_at_commit', $source);
        self::assertStringContainsString('test_feature_state_is_bound_to_publication_and_proof', $source);
        self::assertStringContainsString('test_equal_promotion_is_idempotent_and_unequal_promotion_conflicts', $source);
        self::assertStringContainsString('test_concurrent_promotions_choose_exactly_one_active_proof', $source);
        self::assertStringContainsString('waitForWorkerBackendPid', $source);
        self::assertStringContainsString('waitForPostgresWait($lockConnection', $source);
        self::assertStringContainsString('test_migration_round_trip_preserves_registry_contract', $source);
        self::assertStringContainsString('test_preseeded_event_cannot_authorize_a_later_state_transition', $source);
        self::assertStringContainsString('test_raw_publication_insert_without_exact_feature_row_is_rejected_at_commit', $source);
        self::assertStringContainsString('test_raw_backdated_publication_is_rejected', $source);
        self::assertStringContainsString('test_raw_feature_mutation_writes_transactional_outbox', $source);
        self::assertStringContainsString('test_feature_update_fails_fast_while_publication_is_locked', $source);
        self::assertStringContainsString('test_persisted_canary_allowlist_denies_other_tenants', $source);
    }

    public function test_existing_postgres_workflow_executes_publication_gate_fail_closed(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/notification-concurrency.yml');
        self::assertIsArray($workflow);

        $job = $workflow['jobs']['report-publication-postgres-contract'] ?? null;
        self::assertIsArray($job);
        self::assertSame('most_report_publication_testing', $job['env']['DB_DATABASE'] ?? null);
        self::assertSame(1, $job['env']['REPORT_PUBLICATION_POSTGRES_TESTS'] ?? null);
        $commands = implode("\n", array_map(
            static fn (array $step): string => is_string($step['run'] ?? null) ? $step['run'] : '',
            $job['steps'] ?? [],
        ));
        self::assertStringContainsString('git rev-parse HEAD', $commands);
        self::assertStringContainsString('php artisan migrate:fresh --force', $commands);
        self::assertStringContainsString('ReportPublicationRegistryPostgresTest.php', $commands);
        self::assertStringContainsString('--fail-on-skipped', $commands);

        $pullRequestPaths = $workflow['on']['pull_request']['paths'] ?? [];
        $pushPaths = $workflow['on']['push']['paths'] ?? [];
        foreach ([
            'tests/Feature/Reporting/Publication/**',
            'tests/Unit/Reporting/Publication/**',
            'tests/Support/Reporting/Publication/**',
            'tests/Architecture/Reporting/ReportPublicationProofSchemaTest.php',
            'tests/Fixtures/Reporting/Publication/**',
            'docs/reports/contracts/report-publication-proof.v1.schema.json',
        ] as $path) {
            self::assertContains($path, $pullRequestPaths);
            self::assertContains($path, $pushPaths);
        }
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 4).'/database/migrations/2026_08_01_000020_create_report_publication_registry.php';
    }
}
