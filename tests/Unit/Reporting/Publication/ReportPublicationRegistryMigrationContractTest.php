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
        self::assertStringContainsString('COALESCE(previous.disabled_at, previous.published_at)', $source);
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
        self::assertStringContainsString('most_report_publication_owner', $source);
        self::assertStringContainsString('most_report_publication_issuer', $source);
        self::assertStringContainsString('most_report_publication_operator', $source);
        self::assertStringContainsString('most_report_publication_runtime', $source);
        self::assertStringContainsString('most_report_publication_outbox_worker', $source);
        self::assertStringContainsString('SECURITY DEFINER', $source);
        self::assertStringNotContainsString('SET search_path = pg_catalog, public\n', $source);
        self::assertStringContainsString('SET search_path = pg_catalog, public, pg_temp', $source);
        self::assertSame(
            substr_count($source, 'SECURITY DEFINER'),
            substr_count($source, 'SET search_path = pg_catalog, public, pg_temp'),
        );
        self::assertStringContainsString('report_publication_promote(', $source);
        self::assertStringContainsString('report_publication_disable(', $source);
        self::assertStringContainsString('report_publication_configure_feature(', $source);
        self::assertStringContainsString('report_publication_mark_outbox_delivered(', $source);
        self::assertStringContainsString('release_artifact_sha256', $source);
        self::assertStringContainsString(
            "encode(sha256(convert_to(release_artifact_json, 'UTF8')), 'hex') = release_artifact_sha256\n                    ) IS TRUE",
            $source,
        );
        self::assertStringContainsString(
            "jsonb_typeof(release_artifact_json::jsonb -> 'provenance' -> 'run_attempt') = 'number'",
            $source,
        );
        foreach ([
            'FROM public.report_publications',
            'UPDATE public.report_publication_features',
            'INSERT INTO public.report_publication_events',
            'INSERT INTO public.report_publication_outbox',
            'FROM public.report_publication_events AS event',
            'FROM public.report_publication_outbox AS outbox',
            'FROM public.report_publication_features AS feature',
        ] as $qualifiedRelation) {
            self::assertStringContainsString($qualifiedRelation, $source);
        }
        self::assertStringContainsString('p_published_at > clock_timestamp()', $source);
        self::assertStringContainsString("(proof_json -> 'versions' ->> 'contract') = contract_version", $source);
        self::assertStringContainsString("(proof_json -> 'release' ->> 'approver_identity') = published_by", $source);
        self::assertStringContainsString('REVOKE ALL ON TABLE report_publications', $source);
        self::assertStringContainsString(
            'GRANT USAGE, CREATE ON SCHEMA public TO most_report_publication_owner',
            $source,
        );
        self::assertStringContainsString(
            'REVOKE CREATE ON SCHEMA public FROM most_report_publication_owner',
            $source,
        );
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
        self::assertStringContainsString('test_disabled_same_proof_replay_is_rejected_before_insert', $source);
        self::assertStringContainsString('test_raw_feature_mutation_writes_transactional_outbox', $source);
        self::assertStringContainsString('test_feature_update_fails_fast_while_publication_is_locked', $source);
        self::assertStringContainsString('test_persisted_canary_allowlist_denies_other_tenants', $source);
        self::assertStringContainsString('test_runtime_role_cannot_forge_publication_event_or_outbox_rows', $source);
        self::assertStringContainsString('test_issuer_role_promotes_only_through_the_owned_admission_function', $source);
        self::assertStringContainsString(
            'test_non_superuser_issuer_principal_can_only_use_admission_function_without_owner_bypass',
            $source,
        );
        self::assertStringContainsString('NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', $source);
        self::assertStringContainsString("pg_has_role(current_user, 'most_report_publication_owner', 'MEMBER')", $source);
        self::assertStringContainsString('test_issuer_admission_rejects_null_release_signature_and_evidence', $source);
        self::assertStringContainsString('test_issuer_admission_rejects_future_release_timestamp', $source);
        self::assertStringContainsString('test_issuer_admission_rejects_non_integer_provenance_run_attempt', $source);
        self::assertStringContainsString('test_temp_shadow_cannot_redirect_security_definer_transition_artifacts', $source);
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
        self::assertStringContainsString('tests/Unit/Reporting/Publication', $commands);
        self::assertStringContainsString('ReportPublicationProofSchemaTest.php', $commands);
        self::assertStringContainsString('CsvReportExportRendererTest.php', $commands);
        self::assertStringContainsString('XlsxReportExportRendererTest.php', $commands);
        self::assertStringContainsString('PdfReportExportRendererTest.php', $commands);
        self::assertStringContainsString('ReportExportParityContractTest.php', $commands);
        self::assertStringContainsString('ReportExportStreamingBudgetTest.php', $commands);
        self::assertStringContainsString('ReportPublicationRegistryPostgresTest.php', $commands);
        self::assertStringContainsString('--fail-on-skipped', $commands);

        $discoveryJob = $workflow['jobs']['report-publication-release-request-discovery'] ?? null;
        self::assertIsArray($discoveryJob);
        self::assertEqualsCanonicalizing([
            'procurement-cycle-postgres-contract',
            'procurement-cycle-r15-candidate-evidence',
            'report-publication-postgres-contract',
        ], $discoveryJob['needs'] ?? null);
        self::assertArrayNotHasKey('environment', $discoveryJob);
        $discoveryCommands = implode("\n", array_map(
            static fn (array $step): string => is_string($step['run'] ?? null) ? $step['run'] : '',
            $discoveryJob['steps'] ?? [],
        ));
        self::assertStringContainsString('r15-candidate-evidence-${{ github.sha }}', implode("\n", array_map(
            static fn (array $step): string => is_string($step['with']['name'] ?? null) ? $step['with']['name'] : '',
            $discoveryJob['steps'] ?? [],
        )));
        self::assertStringContainsString('r15_release_request.json', $discoveryCommands);
        self::assertStringContainsString('requests=()', $discoveryCommands);
        self::assertStringNotContainsString('publication-release-requests/*.json', $discoveryCommands);
        self::assertStringContainsString('has_requests=false', $discoveryCommands);
        self::assertStringNotContainsString('REPORT_PUBLICATION_RELEASE_SECRET_KEY_BASE64', $discoveryCommands);

        $releaseJob = $workflow['jobs']['report-publication-release-artifact'] ?? null;
        self::assertIsArray($releaseJob);
        self::assertSame(
            "needs.report-publication-release-request-discovery.outputs.has_requests == 'true'",
            $releaseJob['if'] ?? null,
        );
        self::assertEqualsCanonicalizing([
            'report-publication-release-request-discovery',
            'procurement-cycle-r15-candidate-evidence',
        ], $releaseJob['needs'] ?? null);
        self::assertSame('report-publication-release', $releaseJob['environment'] ?? null);
        $releaseCommands = implode("\n", array_map(
            static fn (array $step): string => is_string($step['run'] ?? null) ? $step['run'] : '',
            $releaseJob['steps'] ?? [],
        ));
        self::assertStringContainsString('GITHUB_EVENT_NAME', $releaseCommands);
        self::assertStringContainsString('GITHUB_REF', $releaseCommands);
        self::assertStringContainsString('GITHUB_REPOSITORY', $releaseCommands);
        self::assertStringContainsString('issue-report-publication-release.php', $releaseCommands);
        self::assertStringNotContainsString('report-publication-proof.valid.json', $releaseCommands);
        self::assertStringNotContainsString('wave-1-candidates.v1.yaml', $releaseCommands);
        self::assertStringContainsString('MOST_R15_RELEASE_TRUSTED_ROOT', $releaseCommands);
        self::assertStringContainsString('r15_release_request.json', $releaseCommands);
        self::assertStringNotContainsString('publication-release-requests/*.json', $releaseCommands);
        self::assertStringNotContainsString('publication-release-requests/*.php', $releaseCommands);
        self::assertContains(
            'actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02',
            array_column($releaseJob['steps'] ?? [], 'uses'),
        );
        self::assertContains(
            'actions/checkout@11d5960a326750d5838078e36cf38b85af677262',
            array_column($releaseJob['steps'] ?? [], 'uses'),
        );
        self::assertContains(
            'shivammathur/setup-php@b604ade2a87db23f8871b7182e69ec5e75effb45',
            array_column($releaseJob['steps'] ?? [], 'uses'),
        );
        $releaseScript = file_get_contents(dirname(__DIR__, 4).'/scripts/issue-report-publication-release.php');
        self::assertIsString($releaseScript);
        self::assertStringContainsString('ReportPublicationReleaseRequestFileLoader', $releaseScript);
        self::assertStringContainsString('ProjectReportPublicationReleaseRequestRegistry', $releaseScript);
        self::assertStringContainsString("getenv('MOST_R15_RELEASE_TRUSTED_ROOT')", $releaseScript);
        self::assertStringContainsString('load($options[\'request\'], $trustedRootReal)', $releaseScript);
        self::assertStringContainsString('realpath($trustedRoot)', $releaseScript);
        self::assertStringContainsString('is_link($trustedRoot)', $releaseScript);
        self::assertStringContainsString('r15-candidate-manifest.json', $releaseScript);
        self::assertStringContainsString('r15-conformance-evidence.json', $releaseScript);
        self::assertStringContainsString('r15-proof-template.json', $releaseScript);
        self::assertStringContainsString('r15_release_request.json', $releaseScript);
        self::assertStringContainsString('report_publication_release_trusted_root_incomplete', $releaseScript);
        self::assertStringContainsString('assertProductionSafe', $releaseScript);
        self::assertStringContainsString('ReportPublicationReleaseBundleWriter', $releaseScript);
        self::assertStringNotContainsString('require $requestPath', $releaseScript);
        self::assertStringNotContainsString('report-publication-proof.valid.json', $releaseScript);

        $pullRequestPaths = $workflow['on']['pull_request']['paths'] ?? [];
        $pushPaths = $workflow['on']['push']['paths'] ?? [];
        foreach ([
            'tests/Feature/Reporting/Publication/**',
            'tests/Unit/Reporting/Publication/**',
            'tests/Support/Reporting/Publication/**',
            'tests/Unit/Reporting/Exports/**',
            'tests/Contract/Reporting/ReportExportParityContractTest.php',
            'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
            'tests/Architecture/Reporting/ReportPublicationProofSchemaTest.php',
            'tests/Fixtures/Reporting/Publication/**',
            'docs/reports/contracts/report-publication-proof.v1.schema.json',
            'resources/views/reports/exports/canonical-report-pdf.blade.php',
            'config/dompdf.php',
            'lang/en/reports.php',
            'lang/ru/reports.php',
            'scripts/issue-report-publication-release.php',
            'build/reports/publication-release-requests/**',
        ] as $path) {
            self::assertContains($path, $pullRequestPaths);
            self::assertContains($path, $pushPaths);
        }
    }

    public function test_discovery_has_requests_is_false_when_same_run_candidate_has_no_request(): void
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/notification-concurrency.yml');
        $job = $workflow['jobs']['report-publication-release-request-discovery'] ?? null;
        self::assertIsArray($job);
        $commands = implode("\n", array_map(
            static fn (array $step): string => is_string($step['run'] ?? null) ? $step['run'] : '',
            $job['steps'] ?? [],
        ));
        self::assertStringContainsString('if [[ -f "$request" && ! -L "$request" ]]', $commands);
        self::assertStringContainsString('has_requests=false', $commands);
        self::assertStringNotContainsString('build/reports/publication-release-requests', $commands);
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 4).'/database/migrations/2026_08_01_000020_create_report_publication_registry.php';
    }
}
