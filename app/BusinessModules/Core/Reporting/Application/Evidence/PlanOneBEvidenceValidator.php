<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PlanOneBEvidenceValidator
{
    private const GATE_SPECS = [
        'plan1a_handoff' => ['contract_json', ['immutable_history', 'forward_only_lineage', 'exact_ownership_manifest', 'strict_clean_preflight', 'fresh_runner_counts', 'verify_existing_no_write']],
        'ownership_boundary' => ['architecture_json', ['plan1a_symbol_intersection_empty', 'plans_2_3_candidate_only', 'plan1c_publication_owner', 'plan4_rollout_owner']],
        'run_state_machine' => ['unit_json', ['six_state_transition_matrix', 'audit_precedes_ready']],
        'run_idempotency' => ['postgresql_json', ['same_org_cross_actor_reuse', 'changed_body_conflict', 'other_org_independence', 'retry_key_replay']],
        'snapshot_identity' => ['contract_json', ['validator_equalities', 'decimal_grammar_negative_zero', 'source_projection_sort', 'duplicate_identity', 'no_input_mutation', 'expired_status_only', 'expired_data_fail_closed']],
        'snapshot_seal_trust' => ['cryptographic_json', ['typed_structural_reasons', 'official_seal_mapping', 'closed_public_key_map', 'sodium_detached_verification', 'trust_case_matrix', 'signed_field_mutations', 'payload_binding']],
        'typed_data_classification' => ['authorization_json', ['sensitive_access_typed', 'audit_access_typed', 'heuristics_absent']],
        'rows_cursor_drill_parity' => ['contract_json', ['rows_cursor_identity', 'drill_down_identity', 'summary_semantic_parity']],
        'row_stream_shape' => ['unit_json', ['one_row_envelope', 'bounded_internal_chunks', 'nested_shape_rejected', 'identity_drift_rejected']],
        'export_state_machine' => ['unit_json', ['seven_state_transition_matrix', 'retry_parent_ready_unexpired', 'retry_denial_before_side_effects']],
        'export_idempotency' => ['postgresql_json', ['same_org_cross_actor_reuse', 'changed_body_conflict', 'other_org_independence', 'retry_key_replay', 'parent_run_fence']],
        'dispatch_outbox_atomicity' => ['postgresql_json', ['closed_named_schemas', 'aggregate_transport_rollback', 'unique_event_keys', 'closed_subjects']],
        'dispatch_lease_recovery' => ['postgresql_json', ['skip_locked_claims', 'lease_fencing_reclaim', 'deterministic_backoff', 'publication_redelivery']],
        'dispatch_dead_letter' => ['postgresql_json', ['run_attempt_12_atomic_failure', 'export_attempt_12_atomic_failure', 'pre_table_branch_absent']],
        'audit_outbox_delivery' => ['postgresql_json', ['id_only_mapping', 'transactional_intent', 'append_ack_order', 'append_replay', 'lease_fenced_backoff', 'attempt_12_dead_letter', 'critical_alerting']],
        'current_async_authorization' => ['postgresql_json', ['typed_scope_cutover', 'exact_resource_decisions', 'repeatable_read_snapshot', 'state_operation_matrix', 'revocation_before_side_effects', 'request_globals_bypassed', 'authority_cache_bypassed', 'atomic_cutover_rollback']],
        'execution_attempt_leases' => ['queue_json', ['runtime_inequalities', 'authority_free_claim', 'same_token_renewal', 'token_fencing', 'leased_failure', 'watchdog_requeue', 'job_failed_aba_fence']],
        'renderer_parity' => ['parity_json', ['csv_semantic_identity', 'xlsx_semantic_identity', 'pdf_semantic_identity']],
        'pdf_renderer_budget' => ['performance_json', ['locked_dependency_versions', 'definition_budget_registry', 'row_boundary_5000', 'page_html_pdf_memory_limits', 'safe_failure_mapping', 'retry_cleanup']],
        'streaming_budget' => ['performance_json', ['bounded_chunks', 'bounded_peak_memory', 'bounded_artifact_bytes']],
        'file_service_call_graph' => ['architecture_json', ['multipart_create', 'multipart_upload_part', 'multipart_complete', 'multipart_abort', 'exact_version_head', 'temporary_link', 'exact_version_delete']],
        's3_version_race' => ['s3_json', ['conditional_race_winner', 'loser_abort_once', 'post_completion_identity', 'metadata_drift_fail_closed']],
        'audit_fail_closed' => ['postgresql_json', ['ready_transition_rollback', 'terminal_transition_rollback', 'remote_io_outside_transaction']],
        'retention_exact_version' => ['s3_json', ['exact_version_inventory', 'lease_fenced_delete', 'historical_identity_retained', 'replay_idempotency']],
        'action_bindings' => ['architecture_json', ['one_handler_per_action', 'thin_controllers', 'catalog_publication_unbound']],
        'error_retryability' => ['contract_json', ['status_mapping', 'retryability_mapping', 'technical_message_absent']],
        'run_export_observability' => ['observability_json', ['bounded_run_families', 'bounded_export_families', 'non_run_export_family_absent']],
        'static_analysis' => ['phpstan_json', ['changed_php_syntax', 'changed_php_phpstan']],
    ];

    private const MEASUREMENT_SPECS = [
        'pdf_renderer_budget' => [
            ['pdf_detail_rows', 'rows'],
            ['pdf_pages', 'pages'],
            ['pdf_html_bytes', 'bytes'],
            ['pdf_output_bytes', 'bytes'],
            ['pdf_memory_delta_bytes', 'bytes'],
        ],
        'streaming_budget' => [
            ['stream_chunk_rows', 'rows'],
            ['stream_peak_memory_bytes', 'bytes'],
            ['stream_artifact_bytes', 'bytes'],
        ],
    ];

    private const PLAN_ONE_A_SYMBOLS = [
        'CandidateReportDefinition',
        'CreateReportExportData',
        'CreateReportRunData',
        'PublishedReportDefinition',
        'ReportCursor',
        'ReportDataProvider',
        'ReportDefinition',
        'ReportDefinitionBinding',
        'ReportDefinitionBindingMap',
        'ReportDownloadLink',
        'ReportDrillDownProvider',
        'ReportExport',
        'ReportPage',
        'ReportQuery',
        'ReportRowQuery',
        'ReportRun',
    ];

    private const PLAN_ONE_B_EVIDENCE_SYMBOLS = [
        'PlanOneACompletionRef',
        'PlanOneACompletionVerifier',
        'PlanOneBEvidenceBuilder',
        'PlanOneBEvidenceValidator',
    ];

    private const EXTERNAL_PLAN_OWNERS = [
        'plan_1c_publication_registry',
        'plan_2_candidate_provider_bindings',
        'plan_3_candidate_provider_bindings',
        'plan_4_evidence_verification_rollout',
    ];

    private const HANDOFF = [
        'plans_2_and_3' => 'plan_1a_provider_ports_candidate_bindings_only',
        'plan_1c' => 'published_registry_map_and_all_publication_transitions',
        'plan_4' => 'evidence_verification_and_deployment_rollout_only',
        'artifact_path' => 'build/reports/plan-1b-completion.json',
        'digest_algorithm' => 'sha256',
    ];

    public function __construct(private PlanOneACompletionRef $verifiedPlanOneA) {}

    public function validate(array $document): void
    {
        if (! $this->hasExactKeys($document, [
            'schema_version',
            'plan_id',
            'evidence_scope',
            'status',
            'generated_at',
            'plan_1a_reference',
            'repository_revision',
            'gates',
            'ownership',
            'performance_measurements',
            'unresolved_risks',
            'handoff',
        ])
            || $document['schema_version'] !== '1.0.0'
            || $document['plan_id'] !== '1b'
            || ! in_array($document['evidence_scope'], ['deterministic_fixture', 'ci'], true)
            || $document['status'] !== 'passed'
            || ! $this->isTimestamp($document['generated_at'])
            || ! $this->isSha1($document['repository_revision'])
            || ! $this->containsNoSubscriptionTelemetry($document)) {
            $this->fail();
        }

        $this->validatePlanOneAReference($document['plan_1a_reference']);
        $measurements = $this->validateGates($document['gates'], $document['repository_revision']);
        if (! is_array($document['performance_measurements'])
            || CanonicalJson::encode($document['performance_measurements']) !== CanonicalJson::encode($measurements)) {
            $this->fail();
        }
        $this->validateOwnership($document['ownership']);
        $this->validateRisks($document['unresolved_risks']);
        $this->validateHandoff($document['handoff']);
    }

    private function validatePlanOneAReference(mixed $reference): void
    {
        if (! is_array($reference)
            || array_is_list($reference)
            || ! $this->hasExactKeys($reference, ['lock_sha256', 'evidence_sha256', 'generated_at', 'status'])
            || ! $this->isSha256($reference['lock_sha256'])
            || ! $this->isSha256($reference['evidence_sha256'])
            || ! $this->isTimestamp($reference['generated_at'])
            || $reference['status'] !== 'passed'
            || ! hash_equals($this->verifiedPlanOneA->lockSha256, $reference['lock_sha256'])
            || ! hash_equals($this->verifiedPlanOneA->evidenceSha256, $reference['evidence_sha256'])
            || $reference['generated_at'] !== $this->formatTimestamp($this->verifiedPlanOneA->generatedAt)
            || $reference['status'] !== $this->verifiedPlanOneA->status) {
            $this->fail();
        }
    }

    private function validateGates(mixed $gates, string $repositoryRevision): array
    {
        if (! is_array($gates) || ! array_is_list($gates) || count($gates) !== count(self::GATE_SPECS)) {
            $this->fail();
        }

        $allMeasurements = [];
        foreach (array_keys(self::GATE_SPECS) as $index => $gateId) {
            $gate = $gates[$index];
            [$artifactType, $requiredChecks] = self::GATE_SPECS[$gateId];
            $command = $this->command($gateId, $artifactType);
            if (! is_array($gate)
                || array_is_list($gate)
                || ! $this->hasExactKeys($gate, ['id', 'status', 'command', 'result', 'duration_ms', 'artifacts', 'measurements'])
                || $gate['id'] !== $gateId
                || $gate['status'] !== 'passed'
                || $gate['command'] !== $command
                || ! is_int($gate['duration_ms'])
                || $gate['duration_ms'] < 0) {
                $this->fail();
            }
            $this->validateResult($gate['result'], $requiredChecks);
            $this->validateArtifact($gate['artifacts'], $gateId, $artifactType, $repositoryRevision);
            $gateMeasurements = $this->validateMeasurements($gate['measurements'], $gateId);
            array_push($allMeasurements, ...$gateMeasurements);
        }

        return $allMeasurements;
    }

    private function validateResult(mixed $result, array $requiredChecks): void
    {
        if (! is_array($result)
            || array_is_list($result)
            || ! $this->hasExactKeys($result, ['exit_code', 'tests', 'assertions', 'required_checks'])
            || $result['exit_code'] !== 0
            || ! is_int($result['tests'])
            || $result['tests'] < 0
            || ! is_int($result['assertions'])
            || $result['assertions'] < 0
            || $result['required_checks'] !== $requiredChecks) {
            $this->fail();
        }
    }

    private function validateArtifact(
        mixed $artifacts,
        string $gateId,
        string $artifactType,
        string $repositoryRevision,
    ): void {
        if (! is_array($artifacts)
            || ! array_is_list($artifacts)
            || count($artifacts) !== 1
            || ! is_array($artifacts[0])
            || array_is_list($artifacts[0])
            || ! $this->hasExactKeys($artifacts[0], ['id', 'type', 'sha256', 'repository_revision'])
            || $artifacts[0]['id'] !== 'plan1b.gate.'.$gateId
            || $artifacts[0]['type'] !== $artifactType
            || ! $this->isSha256($artifacts[0]['sha256'])
            || $artifacts[0]['repository_revision'] !== $repositoryRevision) {
            $this->fail();
        }
    }

    private function validateMeasurements(mixed $measurements, string $gateId): array
    {
        $specs = self::MEASUREMENT_SPECS[$gateId] ?? [];
        if (! is_array($measurements) || ! array_is_list($measurements) || count($measurements) !== count($specs)) {
            $this->fail();
        }
        foreach ($specs as $index => [$id, $unit]) {
            $measurement = $measurements[$index];
            if (! is_array($measurement)
                || array_is_list($measurement)
                || ! $this->hasExactKeys($measurement, ['id', 'value', 'unit', 'limit', 'status'])
                || $measurement['id'] !== $id
                || ! is_int($measurement['value'])
                || $measurement['value'] < 0
                || $measurement['unit'] !== $unit
                || ! is_int($measurement['limit'])
                || $measurement['limit'] < 1
                || $measurement['value'] > $measurement['limit']
                || $measurement['status'] !== 'passed') {
                $this->fail();
            }
        }

        return $measurements;
    }

    private function validateOwnership(mixed $ownership): void
    {
        if (! is_array($ownership)
            || array_is_list($ownership)
            || ! $this->hasExactKeys($ownership, ['plan_1a_symbols', 'plan_1b_symbols', 'external_plan_owners'])
            || $ownership['plan_1a_symbols'] !== self::PLAN_ONE_A_SYMBOLS
            || $ownership['plan_1b_symbols'] !== self::PLAN_ONE_B_EVIDENCE_SYMBOLS
            || $ownership['external_plan_owners'] !== self::EXTERNAL_PLAN_OWNERS
            || array_intersect($ownership['plan_1a_symbols'], $ownership['plan_1b_symbols']) !== []) {
            $this->fail();
        }
    }

    private function validateRisks(mixed $risks): void
    {
        if (! is_array($risks) || ! array_is_list($risks)) {
            $this->fail();
        }
        foreach ($risks as $risk) {
            if (! is_string($risk) || trim($risk) !== $risk || $risk === '') {
                $this->fail();
            }
        }
        $sorted = $risks;
        sort($sorted, SORT_STRING);
        if ($risks !== $sorted || count($risks) !== count(array_unique($risks))) {
            $this->fail();
        }
    }

    private function validateHandoff(mixed $handoff): void
    {
        if (! is_array($handoff)
            || array_is_list($handoff)
            || ! $this->hasExactKeys($handoff, array_keys(self::HANDOFF))) {
            $this->fail();
        }
        foreach (self::HANDOFF as $key => $value) {
            if ($handoff[$key] !== $value) {
                $this->fail();
            }
        }
    }

    private function command(string $gateId, string $artifactType): string
    {
        return match ($artifactType) {
            'postgresql_json' => 'vendor/bin/phpunit --testsuite reports_'.$gateId.'_postgresql',
            's3_json' => 'vendor/bin/phpunit --testsuite reports_'.$gateId.'_s3',
            'performance_json' => 'vendor/bin/phpunit --testsuite reports_'.$gateId.'_performance',
            'phpstan_json' => 'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist',
            default => 'vendor/bin/phpunit --testsuite reports_'.$gateId,
        };
    }

    private function containsNoSubscriptionTelemetry(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/(?:subscription|telemetry)/i', $value) !== 1;
        }
        if (! is_array($value)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if ((is_string($key) && ! $this->containsNoSubscriptionTelemetry($key))
                || ! $this->containsNoSubscriptionTelemetry($item)) {
                return false;
            }
        }

        return true;
    }

    private function isTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function isSha1(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function fail(): never
    {
        throw new InvalidArgumentException('plan_one_b_evidence_invalid');
    }
}
