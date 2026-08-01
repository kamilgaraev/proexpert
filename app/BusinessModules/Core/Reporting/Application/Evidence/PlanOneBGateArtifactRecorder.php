<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use JsonException;

final class PlanOneBGateArtifactRecorder
{
    private const GATE_SPECS = [
        'plan1a_handoff' => ['contract_json', 'contract_case', ['immutable_history', 'forward_only_lineage', 'exact_ownership_manifest', 'strict_clean_preflight', 'fresh_runner_counts', 'verify_existing_no_write']],
        'ownership_boundary' => ['architecture_json', 'architecture_rule', ['plan1a_symbol_intersection_empty', 'plans_2_3_candidate_only', 'plan1c_publication_owner', 'plan4_rollout_owner']],
        'run_state_machine' => ['unit_json', 'unit_case', ['six_state_transition_matrix', 'audit_precedes_ready']],
        'run_idempotency' => ['postgresql_json', 'postgresql_case', ['same_org_cross_actor_reuse', 'changed_body_conflict', 'other_org_independence', 'retry_key_replay']],
        'snapshot_identity' => ['contract_json', 'contract_case', ['validator_equalities', 'decimal_grammar_negative_zero', 'source_projection_sort', 'duplicate_identity', 'no_input_mutation', 'expired_status_only', 'expired_data_fail_closed']],
        'snapshot_seal_trust' => ['cryptographic_json', 'cryptographic_case', ['typed_structural_reasons', 'official_seal_mapping', 'closed_public_key_map', 'sodium_detached_verification', 'trust_case_matrix', 'signed_field_mutations', 'payload_binding']],
        'typed_data_classification' => ['authorization_json', 'authorization_case', ['sensitive_access_typed', 'audit_access_typed', 'heuristics_absent']],
        'rows_cursor_drill_parity' => ['contract_json', 'contract_case', ['rows_cursor_identity', 'drill_down_identity', 'summary_semantic_parity']],
        'row_stream_shape' => ['unit_json', 'unit_case', ['one_row_envelope', 'bounded_internal_chunks', 'nested_shape_rejected', 'identity_drift_rejected']],
        'export_state_machine' => ['unit_json', 'unit_case', ['seven_state_transition_matrix', 'retry_parent_ready_unexpired', 'retry_denial_before_side_effects']],
        'export_idempotency' => ['postgresql_json', 'postgresql_case', ['same_org_cross_actor_reuse', 'changed_body_conflict', 'other_org_independence', 'retry_key_replay', 'parent_run_fence']],
        'dispatch_outbox_atomicity' => ['postgresql_json', 'postgresql_case', ['closed_named_schemas', 'aggregate_transport_rollback', 'unique_event_keys', 'closed_subjects']],
        'dispatch_lease_recovery' => ['postgresql_json', 'postgresql_case', ['skip_locked_claims', 'lease_fencing_reclaim', 'deterministic_backoff', 'publication_redelivery']],
        'dispatch_dead_letter' => ['postgresql_json', 'postgresql_case', ['run_attempt_12_atomic_failure', 'export_attempt_12_atomic_failure', 'pre_table_branch_absent']],
        'audit_outbox_delivery' => ['postgresql_json', 'postgresql_case', ['id_only_mapping', 'transactional_intent', 'append_ack_order', 'append_replay', 'lease_fenced_backoff', 'attempt_12_dead_letter', 'critical_alerting']],
        'current_async_authorization' => ['postgresql_json', 'postgresql_case', ['typed_scope_cutover', 'exact_resource_decisions', 'repeatable_read_snapshot', 'state_operation_matrix', 'revocation_before_side_effects', 'request_globals_bypassed', 'authority_cache_bypassed', 'atomic_cutover_rollback']],
        'execution_attempt_leases' => ['queue_json', 'queue_case', ['runtime_inequalities', 'authority_free_claim', 'same_token_renewal', 'token_fencing', 'leased_failure', 'watchdog_requeue', 'job_failed_aba_fence']],
        'renderer_parity' => ['parity_json', 'parity_case', ['csv_semantic_identity', 'xlsx_semantic_identity', 'pdf_semantic_identity']],
        'pdf_renderer_budget' => ['performance_json', 'performance_case', ['locked_dependency_versions', 'definition_budget_registry', 'row_boundary_5000', 'page_html_pdf_memory_limits', 'safe_failure_mapping', 'retry_cleanup']],
        'streaming_budget' => ['performance_json', 'performance_case', ['bounded_chunks', 'bounded_peak_memory', 'bounded_artifact_bytes']],
        'file_service_call_graph' => ['architecture_json', 'architecture_rule', ['multipart_create', 'multipart_upload_part', 'multipart_complete', 'multipart_abort', 'exact_version_head', 'temporary_link', 'exact_version_delete']],
        's3_version_race' => ['s3_json', 's3_case', ['conditional_race_winner', 'loser_abort_once', 'post_completion_identity', 'metadata_drift_fail_closed']],
        'audit_fail_closed' => ['postgresql_json', 'postgresql_case', ['ready_transition_rollback', 'terminal_transition_rollback', 'remote_io_outside_transaction']],
        'retention_exact_version' => ['s3_json', 's3_case', ['exact_version_inventory', 'lease_fenced_delete', 'historical_identity_retained', 'replay_idempotency']],
        'action_bindings' => ['architecture_json', 'architecture_rule', ['one_handler_per_action', 'thin_controllers', 'catalog_publication_unbound']],
        'error_retryability' => ['contract_json', 'contract_case', ['status_mapping', 'retryability_mapping', 'technical_message_absent']],
        'run_export_observability' => ['observability_json', 'observability_case', ['bounded_run_families', 'bounded_export_families', 'non_run_export_family_absent']],
        'static_analysis' => ['phpstan_json', 'static_analysis_case', ['changed_php_syntax', 'changed_php_phpstan']],
    ];

    private const GATE_TEST_PATHS = [
        'plan1a_handoff' => [
            'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
            'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
            'tests/Unit/Reporting/Evidence/PlanOneACompletionVerifierTest.php',
            'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
            'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
        ],
        'ownership_boundary' => [
            'tests/Architecture/Reporting/PlanOneBOwnershipBoundaryTest.php',
            'tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php',
            'tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php',
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
        ],
        'run_state_machine' => [
            'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
            'tests/Unit/Reporting/Execution/ExecutionContractsTest.php',
            'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
        ],
        'run_idempotency' => [
            'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
        ],
        'snapshot_identity' => [
            'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
            'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
            'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
            'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
            'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
        ],
        'snapshot_seal_trust' => [
            'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
            'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
            'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
            'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
        ],
        'typed_data_classification' => [
            'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
            'tests/Unit/Reporting/Access/CurrentReportAuthorizationFactsTest.php',
            'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
            'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
        ],
        'rows_cursor_drill_parity' => [
            'tests/Contract/Reporting/ReportRowsParityContractTest.php',
            'tests/Contract/Reporting/ReportExportParityContractTest.php',
            'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
            'tests/Unit/Reporting/Cursors/SignedReportCursorCodecTest.php',
        ],
        'row_stream_shape' => [
            'tests/Unit/Reporting/Rows/ReportRowChunkReaderTest.php',
        ],
        'export_state_machine' => [
            'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
            'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
        ],
        'export_idempotency' => [
            'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
            'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
        ],
        'dispatch_outbox_atomicity' => [
            'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
            'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
        ],
        'dispatch_lease_recovery' => [
            'tests/Unit/Reporting/Dispatch/ReportDispatchBackoffPolicyTest.php',
            'tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php',
            'tests/Unit/Reporting/Dispatch/ReportDispatchIntentReconcilerTest.php',
            'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
        ],
        'dispatch_dead_letter' => [
            'tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php',
            'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ],
        'audit_outbox_delivery' => [
            'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
            'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
            'tests/Integration/Reporting/Audit/CoreReportAuditIntentConsumerTest.php',
            'tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php',
            'tests/Unit/Reporting/Audit/ReportAuditOutboxSchedulerTest.php',
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
        ],
        'current_async_authorization' => [
            'tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php',
            'tests/Unit/Reporting/Execution/CurrentReportAuthorizationTargetTest.php',
            'tests/Unit/Reporting/Access/ReportAuthorizationSubjectTest.php',
            'tests/Unit/Reporting/Access/CurrentReportAuthorizationFactsTest.php',
            'tests/Unit/Reporting/Access/CurrentReportPermissionDecisionTest.php',
            'tests/Unit/Reporting/Access/ReportScopedResourceAccessDecisionTest.php',
            'tests/Unit/Reporting/Access/ReportScopedResourceAuthorizerContractTest.php',
            'tests/Unit/Reporting/Access/ReportHttpAuthorizationOrchestratorTest.php',
            'tests/Unit/Reporting/Access/LaravelReportHttpAuthorizationTargetResolverTest.php',
            'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorTest.php',
            'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorBehaviorTest.php',
            'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorRaceTest.php',
            'tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizerTest.php',
            'tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizationRaceTest.php',
            'tests/Feature/Reporting/Persistence/ReportTypedResourceScopeCutoverMigrationTest.php',
            'tests/Architecture/Reporting/ReportCurrentAuthorizationContractTest.php',
            'tests/Architecture/Reporting/ReportAuthorizationSubjectReaderOwnershipTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportAuthorizationSubjectReaderTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunAsyncContextSeedReaderTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAsyncContextSeedReaderTest.php',
            'tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAuthorizationPostgresTest.php',
        ],
        'execution_attempt_leases' => [
            'tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php',
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            'tests/Unit/Reporting/Execution/ReportRunExecutionWatchdogTest.php',
            'tests/Unit/Reporting/Execution/ReportRunLeaseRecoveryStoreContractTest.php',
            'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
            'tests/Unit/Reporting/Execution/ReportExportLeaseRecoveryStoreContractTest.php',
            'tests/Unit/Reporting/Exports/ReportExportAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php',
            'tests/Unit/Reporting/Jobs/GenerateReportExportJobTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ],
        'renderer_parity' => [
            'tests/Contract/Reporting/ReportExportParityContractTest.php',
            'tests/Unit/Reporting/Exports/CsvReportExportRendererTest.php',
            'tests/Unit/Reporting/Exports/XlsxReportExportRendererTest.php',
            'tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php',
            'tests/Unit/Reporting/Exports/ReportExportRendererRegistryTest.php',
        ],
        'pdf_renderer_budget' => [
            'tests/Unit/Reporting/Evidence/PlanOneBGateArtifactRecorderTest.php',
            'tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php',
            'tests/Unit/Reporting/Exports/ReportExportRendererRegistryTest.php',
            'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
            'tests/Unit/Reporting/Jobs/GenerateReportExportJobTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            'tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php',
            'tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php',
        ],
        'streaming_budget' => [
            'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
            'tests/Unit/Reporting/Exports/CsvReportExportRendererTest.php',
            'tests/Unit/Reporting/Exports/XlsxReportExportRendererTest.php',
            'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ],
        'file_service_call_graph' => [
            'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
            'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            'tests/Feature/Reporting/Actions/ReportDownloadLinkHandlerTest.php',
            'tests/Unit/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
        ],
        's3_version_race' => [
            'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
            'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            'tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ],
        'audit_fail_closed' => [
            'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
            'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ],
        'retention_exact_version' => [
            'tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php',
            'tests/Unit/Reporting/Retention/ExpireReportsServiceTest.php',
            'tests/Unit/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
            'tests/Feature/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
            'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
        ],
        'action_bindings' => [
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            'tests/Architecture/Reporting/ThinReportControllerTest.php',
            'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
            'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
            'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
        ],
        'error_retryability' => [
            'tests/Unit/Reporting/Errors/ReportExecutionErrorMappingTest.php',
            'tests/Unit/Reporting/Errors/ReportErrorCatalogTest.php',
            'tests/Unit/Reporting/Errors/ReportErrorResponseFactoryTest.php',
        ],
        'run_export_observability' => [
            'tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php',
        ],
        'static_analysis' => [
            'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php',
            'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php',
            'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBGateArtifactRecorder.php',
            'scripts/reporting/run-plan-1b-gate.php',
        ],
    ];

    private const GATE_CHECK_SUITES = [
        'plan1a_handoff' => [
            'immutable_history' => [
                'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
                'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
            ],
            'forward_only_lineage' => [
                'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
                'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
            ],
            'exact_ownership_manifest' => [
                'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
                'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
            ],
            'strict_clean_preflight' => [
                'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
                'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
            ],
            'fresh_runner_counts' => [
                'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
            ],
            'verify_existing_no_write' => [
                'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
            ],
        ],
        'ownership_boundary' => [
            'plan1a_symbol_intersection_empty' => [
                'tests/Architecture/Reporting/PlanOneBOwnershipBoundaryTest.php',
                'tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php',
            ],
            'plans_2_3_candidate_only' => [
                'tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php',
            ],
            'plan1c_publication_owner' => [
                'tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php',
                'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            ],
            'plan4_rollout_owner' => [
                'tests/Architecture/Reporting/PlanOneBCrossFileSymbolTest.php',
            ],
        ],
        'run_state_machine' => [
            'six_state_transition_matrix' => [
                'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            ],
            'audit_precedes_ready' => [
                'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            ],
        ],
        'run_idempotency' => [
            'same_org_cross_actor_reuse' => [
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            ],
            'changed_body_conflict' => [
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
                'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
            ],
            'other_org_independence' => [
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
            ],
            'retry_key_replay' => [
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
                'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
            ],
        ],
        'snapshot_identity' => [
            'validator_equalities' => [
                'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
            ],
            'decimal_grammar_negative_zero' => [
                'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
            ],
            'source_projection_sort' => [
                'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
            ],
            'duplicate_identity' => [
                'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
            ],
            'no_input_mutation' => [
                'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
            ],
            'expired_status_only' => [
                'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
            ],
            'expired_data_fail_closed' => [
                'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
                'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
            ],
        ],
        'snapshot_seal_trust' => [
            'typed_structural_reasons' => [
                'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            ],
            'official_seal_mapping' => [
                'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            ],
            'closed_public_key_map' => [
                'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
            ],
            'sodium_detached_verification' => [
                'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
            ],
            'trust_case_matrix' => [
                'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
            ],
            'signed_field_mutations' => [
                'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
            ],
            'payload_binding' => [
                'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
                'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
            ],
        ],
        'typed_data_classification' => [
            'sensitive_access_typed' => [
                'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
                'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
            ],
            'audit_access_typed' => [
                'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
                'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
            ],
            'heuristics_absent' => [
                'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
            ],
        ],
        'rows_cursor_drill_parity' => [
            'rows_cursor_identity' => [
                'tests/Contract/Reporting/ReportRowsParityContractTest.php',
                'tests/Unit/Reporting/Cursors/SignedReportCursorCodecTest.php',
            ],
            'drill_down_identity' => [
                'tests/Unit/Reporting/Actions/ReportReadHandlersTest.php',
                'tests/Unit/Reporting/Cursors/SignedReportCursorCodecTest.php',
            ],
            'summary_semantic_parity' => [
                'tests/Contract/Reporting/ReportExportParityContractTest.php',
            ],
        ],
        'row_stream_shape' => [
            'one_row_envelope' => [
                'tests/Unit/Reporting/Rows/ReportRowChunkReaderTest.php',
            ],
            'bounded_internal_chunks' => [
                'tests/Unit/Reporting/Rows/ReportRowChunkReaderTest.php',
            ],
            'nested_shape_rejected' => [
                'tests/Unit/Reporting/Rows/ReportRowChunkReaderTest.php',
            ],
            'identity_drift_rejected' => [
                'tests/Unit/Reporting/Rows/ReportRowChunkReaderTest.php',
            ],
        ],
        'export_state_machine' => [
            'seven_state_transition_matrix' => [
                'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
            ],
            'retry_parent_ready_unexpired' => [
                'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            ],
            'retry_denial_before_side_effects' => [
                'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            ],
        ],
        'export_idempotency' => [
            'same_org_cross_actor_reuse' => [
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
            ],
            'changed_body_conflict' => [
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
                'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            ],
            'other_org_independence' => [
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
            ],
            'retry_key_replay' => [
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
                'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            ],
            'parent_run_fence' => [
                'tests/Unit/Reporting/Actions/ReportExportHandlersTest.php',
            ],
        ],
        'dispatch_outbox_atomicity' => [
            'closed_named_schemas' => [
                'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
            ],
            'aggregate_transport_rollback' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
            ],
            'unique_event_keys' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
            ],
            'closed_subjects' => [
                'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
            ],
        ],
        'dispatch_lease_recovery' => [
            'skip_locked_claims' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
            ],
            'lease_fencing_reclaim' => [
                'tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php',
                'tests/Unit/Reporting/Dispatch/ReportDispatchIntentReconcilerTest.php',
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
            ],
            'deterministic_backoff' => [
                'tests/Unit/Reporting/Dispatch/ReportDispatchBackoffPolicyTest.php',
                'tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php',
            ],
            'publication_redelivery' => [
                'tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php',
            ],
        ],
        'dispatch_dead_letter' => [
            'run_attempt_12_atomic_failure' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            ],
            'export_attempt_12_atomic_failure' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
                'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            ],
            'pre_table_branch_absent' => [
                'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            ],
        ],
        'audit_outbox_delivery' => [
            'id_only_mapping' => [
                'tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php',
                'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
            ],
            'transactional_intent' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
                'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
            ],
            'append_ack_order' => [
                'tests/Integration/Reporting/Audit/CoreReportAuditIntentConsumerTest.php',
                'tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php',
            ],
            'append_replay' => [
                'tests/Integration/Reporting/Audit/CoreReportAuditIntentConsumerTest.php',
                'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
            ],
            'lease_fenced_backoff' => [
                'tests/Unit/Reporting/Audit/AppendReportAuditEventJobTest.php',
                'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
            ],
            'attempt_12_dead_letter' => [
                'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
            ],
            'critical_alerting' => [
                'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            ],
        ],
        'current_async_authorization' => [
            'typed_scope_cutover' => [
                'tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php',
                'tests/Feature/Reporting/Persistence/ReportTypedResourceScopeCutoverMigrationTest.php',
            ],
            'exact_resource_decisions' => [
                'tests/Unit/Reporting/Access/ReportScopedResourceAccessDecisionTest.php',
                'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorBehaviorTest.php',
            ],
            'repeatable_read_snapshot' => [
                'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorRaceTest.php',
                'tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizationRaceTest.php',
            ],
            'state_operation_matrix' => [
                'tests/Architecture/Reporting/ReportCurrentAuthorizationContractTest.php',
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
                'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            ],
            'revocation_before_side_effects' => [
                'tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php',
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
                'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            ],
            'request_globals_bypassed' => [
                'tests/Architecture/Reporting/ReportAuthorizationSubjectReaderOwnershipTest.php',
                'tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php',
            ],
            'authority_cache_bypassed' => [
                'tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizationRaceTest.php',
                'tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php',
            ],
            'atomic_cutover_rollback' => [
                'tests/Feature/Reporting/Persistence/ReportTypedResourceScopeCutoverMigrationTest.php',
            ],
        ],
        'renderer_parity' => [
            'csv_semantic_identity' => [
                'tests/Contract/Reporting/ReportExportParityContractTest.php',
                'tests/Unit/Reporting/Exports/CsvReportExportRendererTest.php',
            ],
            'xlsx_semantic_identity' => [
                'tests/Contract/Reporting/ReportExportParityContractTest.php',
                'tests/Unit/Reporting/Exports/XlsxReportExportRendererTest.php',
            ],
            'pdf_semantic_identity' => [
                'tests/Contract/Reporting/ReportExportParityContractTest.php',
                'tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php',
            ],
        ],
        'pdf_renderer_budget' => [
            'locked_dependency_versions' => [
                'tests/Unit/Reporting/Evidence/PlanOneBGateArtifactRecorderTest.php',
            ],
            'definition_budget_registry' => [
                'tests/Unit/Reporting/Exports/ReportExportRendererRegistryTest.php',
            ],
            'row_boundary_5000' => [
                'tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php',
                'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
            ],
            'page_html_pdf_memory_limits' => [
                'tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php',
                'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
            ],
            'safe_failure_mapping' => [
                'tests/Unit/Reporting/Exports/PdfReportExportRendererTest.php',
                'tests/Unit/Reporting/Jobs/GenerateReportExportJobTest.php',
            ],
            'retry_cleanup' => [
                'tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php',
                'tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php',
                'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            ],
        ],
        'streaming_budget' => [
            'bounded_chunks' => [
                'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
                'tests/Unit/Reporting/Exports/CsvReportExportRendererTest.php',
                'tests/Unit/Reporting/Exports/XlsxReportExportRendererTest.php',
            ],
            'bounded_peak_memory' => [
                'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
            ],
            'bounded_artifact_bytes' => [
                'tests/Performance/Reporting/ReportExportStreamingBudgetTest.php',
                'tests/Unit/Reporting/Exports/CsvReportExportRendererTest.php',
                'tests/Unit/Reporting/Exports/XlsxReportExportRendererTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
        ],
        'file_service_call_graph' => [
            'multipart_create' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'multipart_upload_part' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'multipart_complete' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'multipart_abort' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'exact_version_head' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'temporary_link' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Feature/Reporting/Actions/ReportDownloadLinkHandlerTest.php',
            ],
            'exact_version_delete' => [
                'tests/Unit/Services/Storage/FileServiceMultipartTest.php',
                'tests/Unit/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
            ],
        ],
        's3_version_race' => [
            'conditional_race_winner' => [
                'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'loser_abort_once' => [
                'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'post_completion_identity' => [
                'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
            'metadata_drift_fail_closed' => [
                'tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php',
                'tests/Unit/Reporting/Exports/S3ReportArtifactStreamTest.php',
            ],
        ],
        'audit_fail_closed' => [
            'ready_transition_rollback' => [
                'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            ],
            'terminal_transition_rollback' => [
                'tests/Feature/Reporting/Persistence/EloquentReportExportStoreTest.php',
                'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
            ],
            'remote_io_outside_transaction' => [
                'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
                'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
            ],
        ],
        'retention_exact_version' => [
            'exact_version_inventory' => [
                'tests/Unit/Reporting/Exports/ReconcileCompletedReportArtifactsTest.php',
                'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
            ],
            'lease_fenced_delete' => [
                'tests/Unit/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
                'tests/Feature/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
            ],
            'historical_identity_retained' => [
                'tests/Unit/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
                'tests/Integration/Reporting/Exports/S3ReportArtifactIntegrationTest.php',
            ],
            'replay_idempotency' => [
                'tests/Feature/Reporting/Retention/DeleteExpiredReportArtifactsServiceTest.php',
            ],
        ],
        'action_bindings' => [
            'one_handler_per_action' => [
                'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            ],
            'thin_controllers' => [
                'tests/Architecture/Reporting/ThinReportControllerTest.php',
                'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
            ],
            'catalog_publication_unbound' => [
                'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            ],
        ],
        'error_retryability' => [
            'status_mapping' => [
                'tests/Unit/Reporting/Errors/ReportExecutionErrorMappingTest.php',
                'tests/Unit/Reporting/Errors/ReportErrorCatalogTest.php',
            ],
            'retryability_mapping' => [
                'tests/Unit/Reporting/Errors/ReportExecutionErrorMappingTest.php',
                'tests/Unit/Reporting/Errors/ReportErrorCatalogTest.php',
            ],
            'technical_message_absent' => [
                'tests/Unit/Reporting/Errors/ReportExecutionErrorMappingTest.php',
                'tests/Unit/Reporting/Errors/ReportErrorResponseFactoryTest.php',
            ],
        ],
        'run_export_observability' => [
            'bounded_run_families' => [
                'tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php',
            ],
            'bounded_export_families' => [
                'tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php',
            ],
            'non_run_export_family_absent' => [
                'tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php',
            ],
        ],
        'static_analysis' => [
            'changed_php_syntax' => [
                'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php',
                'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php',
                'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBGateArtifactRecorder.php',
                'scripts/reporting/run-plan-1b-gate.php',
            ],
            'changed_php_phpstan' => [
                'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceBuilder.php',
                'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBEvidenceValidator.php',
                'app/BusinessModules/Core/Reporting/Application/Evidence/PlanOneBGateArtifactRecorder.php',
                'scripts/reporting/run-plan-1b-gate.php',
            ],
        ],
    ];

    private const EXECUTION_ATTEMPT_CHECK_SUITES = [
        'runtime_inequalities' => [
            'tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php',
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
        ],
        'authority_free_claim' => [
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ],
        'same_token_renewal' => [
            'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php',
        ],
        'token_fencing' => [
            'tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php',
        ],
        'leased_failure' => [
            'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Unit/Reporting/Exports/ReportExportAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php',
            'tests/Unit/Reporting/Jobs/GenerateReportExportJobTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php',
        ],
        'watchdog_requeue' => [
            'tests/Unit/Reporting/Execution/ReportRunExecutionWatchdogTest.php',
            'tests/Unit/Reporting/Execution/ReportRunLeaseRecoveryStoreContractTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php',
            'tests/Unit/Reporting/Execution/ReportExportLeaseRecoveryStoreContractTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportLeaseRecoveryStoreTest.php',
        ],
        'job_failed_aba_fence' => [
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportLeaseRecoveryStoreTest.php',
        ],
    ];

    private const PERFORMANCE_MEASUREMENT_SPECS = [
        'pdf_renderer_budget' => [
            ['pdf_detail_rows', 'rows', 5000],
            ['pdf_pages', 'pages', 20],
            ['pdf_html_bytes', 'bytes', 2000000],
            ['pdf_output_bytes', 'bytes', 2000000],
            ['pdf_memory_delta_bytes', 'bytes', 134217728],
        ],
        'streaming_budget' => [
            ['stream_chunk_rows', 'rows', 5000],
            ['stream_peak_memory_bytes', 'bytes', 134217728],
            ['stream_artifact_bytes', 'bytes', 524288000],
        ],
    ];

    private string $repositoryRoot;

    public function __construct(?string $repositoryRoot = null)
    {
        $root = $repositoryRoot ?? getcwd();
        if (! is_string($root)) {
            $this->fail();
        }
        $resolved = realpath($root);
        if (! is_string($resolved) || ! is_dir($resolved)) {
            $this->fail();
        }
        $this->repositoryRoot = rtrim(str_replace('\\', '/', $resolved), '/');
    }

    public static function definitions(): array
    {
        $definitions = [];
        foreach (array_keys(self::GATE_SPECS) as $gateId) {
            $definitions[$gateId] = self::definition($gateId);
        }

        return $definitions;
    }

    public static function definition(string $gateId): array
    {
        if (! isset(self::GATE_SPECS[$gateId], self::GATE_TEST_PATHS[$gateId])) {
            throw new InvalidArgumentException('plan_one_b_gate_process_result_invalid');
        }
        [$artifactType, $recordKind, $requiredChecks] = self::GATE_SPECS[$gateId];
        $testPaths = self::GATE_TEST_PATHS[$gateId];
        $resultArtifactPath = 'build/reports/gates/results/'.$gateId.($gateId === 'static_analysis' ? '.json' : '.junit.xml');
        $checkSuites = $gateId === 'execution_attempt_leases'
            ? self::EXECUTION_ATTEMPT_CHECK_SUITES
            : (self::GATE_CHECK_SUITES[$gateId] ?? null);
        if (! is_array($checkSuites) || array_keys($checkSuites) !== $requiredChecks) {
            throw new InvalidArgumentException('plan_one_b_gate_process_result_invalid');
        }
        foreach ($checkSuites as $suites) {
            if ($suites === [] || array_diff($suites, $testPaths) !== []) {
                throw new InvalidArgumentException('plan_one_b_gate_process_result_invalid');
            }
        }
        $measurementSpecs = self::PERFORMANCE_MEASUREMENT_SPECS[$gateId] ?? [];
        $measurementArtifactPath = $measurementSpecs === []
            ? null
            : 'build/reports/gates/results/'.$gateId.'.measurements.json';
        $measurementCommand = $measurementSpecs === []
            ? null
            : 'php vendor/bin/phpunit tests/Unit/Reporting/Evidence/PlanOneBGateArtifactRecorderTest.php'
                .' --filter test_writes_requested_performance_measurements --no-coverage';
        $producer = [
            'id' => $gateId === 'static_analysis' ? 'plan1b-static-analysis' : 'phpunit-11-junit',
            'runner_command' => 'php scripts/reporting/run-plan-1b-gate.php '.$gateId,
            'test_paths' => $testPaths,
            'artifact_path' => 'build/reports/gates/'.$gateId.'.json',
            'result_artifact_path' => $resultArtifactPath,
            'measurement_artifact_path' => $measurementArtifactPath,
            'measurement_command' => $measurementCommand,
        ];
        if ($gateId === 'static_analysis') {
            $phpstan = self::phpstanCommand($testPaths);

            return [
                'artifact_id' => 'plan1b.gate.'.$gateId,
                'artifact_type' => $artifactType,
                'record_kind' => $recordKind,
                'producer' => $producer,
                'gate_id' => $gateId,
                'command' => implode(' && ', [
                    ...array_map(static fn (string $path): string => 'php -l '.$path, $testPaths),
                    $phpstan,
                ]),
                'static_phpstan_command' => $phpstan,
                'required_checks' => $requiredChecks,
                'check_suites' => $checkSuites,
                'measurement_specs' => $measurementSpecs,
            ];
        }

        return [
            'artifact_id' => 'plan1b.gate.'.$gateId,
            'artifact_type' => $artifactType,
            'record_kind' => $recordKind,
            'producer' => $producer,
            'gate_id' => $gateId,
            'command' => 'php vendor/bin/phpunit '.implode(' ', $testPaths)
                .' --no-coverage --log-junit '.$resultArtifactPath,
            'required_checks' => $requiredChecks,
            'check_suites' => $checkSuites,
            'measurement_specs' => $measurementSpecs,
        ];
    }

    public function recordPhpUnit(
        string $gateId,
        array $processResult,
        string $resultArtifactPath,
        ?string $measurementArtifactPath,
        string $repositoryRevision,
    ): array {
        $definition = self::definition($gateId);
        if ($gateId === 'static_analysis') {
            $this->fail();
        }
        $this->assertRevision($repositoryRevision);
        $this->validateProcessResult($processResult, $definition['command']);
        [$resultBytes, $resultDigest] = $this->readResultArtifact(
            $resultArtifactPath,
            $definition['producer']['result_artifact_path'],
        );
        $suites = $this->parseJunit($resultBytes);
        if (array_keys($suites) !== $definition['producer']['test_paths']) {
            $this->fail();
        }
        [$measurements, $measurementArtifactDigest] = $this->measurements(
            $definition,
            $measurementArtifactPath,
            $repositoryRevision,
        );
        $records = $this->recordsFromSuites($definition, $suites);

        return $this->envelope(
            $definition,
            $repositoryRevision,
            $processResult,
            $resultDigest,
            array_sum(array_column($suites, 'tests')),
            array_sum(array_column($suites, 'assertions')),
            $records,
            $measurements,
            $measurementArtifactDigest,
        );
    }

    public function recordStaticAnalysis(string $resultArtifactPath, string $repositoryRevision): array
    {
        $definition = self::definition('static_analysis');
        $this->assertRevision($repositoryRevision);
        [$resultBytes, $resultDigest] = $this->readResultArtifact(
            $resultArtifactPath,
            $definition['producer']['result_artifact_path'],
        );
        $result = $this->decode($resultBytes);
        $this->assertExactKeys($result, [
            'schema_version',
            'command',
            'started_at',
            'finished_at',
            'duration_ms',
            'syntax',
            'phpstan',
        ]);
        if ($result['schema_version'] !== '1.0.0'
            || $result['command'] !== $definition['command']
            || ! $this->isTimestamp($result['started_at'])
            || ! $this->isTimestamp($result['finished_at'])
            || ! $this->isOrderedTimeRange($result['started_at'], $result['finished_at'])
            || ! is_int($result['duration_ms'])
            || $result['duration_ms'] < 0
            || ! is_array($result['syntax'])
            || ! array_is_list($result['syntax'])
            || count($result['syntax']) !== count($definition['producer']['test_paths'])) {
            $this->fail();
        }

        $stdout = [];
        $stderr = [];
        foreach ($definition['producer']['test_paths'] as $index => $path) {
            $syntax = $result['syntax'][$index];
            if (! is_array($syntax) || array_is_list($syntax)) {
                $this->fail();
            }
            $this->assertExactKeys($syntax, [
                'path',
                'command',
                'exit_code',
                'started_at',
                'finished_at',
                'duration_ms',
                'stdout',
                'stderr',
            ]);
            if ($syntax['path'] !== $path
                || ! is_file($this->repositoryRoot.'/'.$path)
                || $syntax['command'] !== 'php -l '.$path
                || ! is_string($syntax['stdout'])
                || ! str_contains($syntax['stdout'], 'No syntax errors detected in '.$path)) {
                $this->fail();
            }
            $this->validateProcessResult($this->withoutPath($syntax), 'php -l '.$path);
            $stdout[] = $syntax['stdout'];
            $stderr[] = $syntax['stderr'];
        }

        if (! is_array($result['phpstan']) || array_is_list($result['phpstan'])) {
            $this->fail();
        }
        $this->validateProcessResult($result['phpstan'], $definition['static_phpstan_command']);
        $phpstan = $this->decode($result['phpstan']['stdout']);
        $this->assertExactKeys($phpstan, ['totals', 'files', 'errors']);
        if (! is_array($phpstan['totals'])
            || array_is_list($phpstan['totals'])
            || ! $this->hasExactKeys($phpstan['totals'], ['errors', 'file_errors'])
            || $phpstan['totals']['errors'] !== 0
            || $phpstan['totals']['file_errors'] !== 0
            || ! is_array($phpstan['files'])
            || $phpstan['files'] !== []
            || ! is_array($phpstan['errors'])
            || $phpstan['errors'] !== []) {
            $this->fail();
        }
        $stdout[] = $result['phpstan']['stdout'];
        $stderr[] = $result['phpstan']['stderr'];
        $processResult = [
            'command' => $definition['command'],
            'exit_code' => 0,
            'started_at' => $result['started_at'],
            'finished_at' => $result['finished_at'],
            'duration_ms' => $result['duration_ms'],
            'stdout' => implode("\0", $stdout),
            'stderr' => implode("\0", $stderr),
        ];
        $fileCount = count($definition['producer']['test_paths']);
        $records = array_map(
            static fn (string $check): array => [
                'id' => $check,
                'kind' => $definition['record_kind'],
                'status' => 'passed',
                'tests' => $fileCount,
                'assertions' => $fileCount,
                'suites' => $definition['check_suites'][$check],
            ],
            $definition['required_checks'],
        );

        return $this->envelope(
            $definition,
            $repositoryRevision,
            $processResult,
            $resultDigest,
            $fileCount * 2,
            $fileCount * 2,
            $records,
            [],
            null,
        );
    }

    private static function phpstanCommand(array $paths): string
    {
        return 'php -d memory_limit=1G vendor/bin/phpstan analyse --configuration=phpstan.neon.dist'
            .' --error-format=json --no-progress '.implode(' ', $paths);
    }

    private function parseJunit(string $bytes): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->documentElement?->nodeName !== 'testsuites') {
            $this->fail();
        }
        $nodes = (new DOMXPath($document))->query('//testsuite[@file]');
        if ($nodes === false || $nodes->length === 0) {
            $this->fail();
        }
        $suites = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                $this->fail();
            }
            $path = $this->relativeExistingPath($node->getAttribute('file'));
            if (isset($suites[$path])) {
                $this->fail();
            }
            $tests = $this->integerAttribute($node, 'tests');
            $assertions = $this->integerAttribute($node, 'assertions');
            if ($tests < 1
                || $assertions < 1
                || $this->integerAttribute($node, 'errors') !== 0
                || $this->integerAttribute($node, 'failures') !== 0
                || $this->integerAttribute($node, 'skipped') !== 0) {
                $this->fail();
            }
            $testCases = 0;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'testcase') {
                    $testCases++;
                    foreach ($child->childNodes as $outcome) {
                        if ($outcome instanceof DOMElement
                            && in_array($outcome->nodeName, ['error', 'failure', 'skipped'], true)) {
                            $this->fail();
                        }
                    }
                }
            }
            if ($testCases !== $tests) {
                $this->fail();
            }
            $suites[$path] = ['tests' => $tests, 'assertions' => $assertions];
        }

        return $suites;
    }

    private function recordsFromSuites(array $definition, array $suites): array
    {
        $records = [];
        foreach ($definition['required_checks'] as $check) {
            $mappedSuites = $definition['check_suites'][$check] ?? null;
            if (! is_array($mappedSuites) || ! array_is_list($mappedSuites) || $mappedSuites === []) {
                $this->fail();
            }
            $tests = 0;
            $assertions = 0;
            foreach ($mappedSuites as $suite) {
                if (! isset($suites[$suite])) {
                    $this->fail();
                }
                $tests += $suites[$suite]['tests'];
                $assertions += $suites[$suite]['assertions'];
            }
            $records[] = [
                'id' => $check,
                'kind' => $definition['record_kind'],
                'status' => 'passed',
                'tests' => $tests,
                'assertions' => $assertions,
                'suites' => $mappedSuites,
            ];
        }

        return $records;
    }

    private function measurements(
        array $definition,
        ?string $measurementArtifactPath,
        string $repositoryRevision,
    ): array {
        if ($definition['measurement_specs'] === []) {
            if ($measurementArtifactPath !== null) {
                $this->fail();
            }

            return [[], null];
        }
        if (! is_string($measurementArtifactPath)) {
            $this->fail();
        }
        $expectedPath = $definition['producer']['measurement_artifact_path'];
        $measurementCommand = $definition['producer']['measurement_command'];
        if (! is_string($expectedPath) || ! is_string($measurementCommand)) {
            $this->fail();
        }
        [$bytes, $digest] = $this->readResultArtifact(
            $measurementArtifactPath,
            $expectedPath,
        );
        $result = $this->decode($bytes);
        $this->assertExactKeys($result, [
            'schema_version',
            'gate_id',
            'repository_revision',
            'nonce',
            'raw_measurements_sha256',
            'process',
            'measurements',
        ]);
        if ($result['schema_version'] !== '1.0.0'
            || $result['gate_id'] !== $definition['gate_id']
            || $result['repository_revision'] !== $repositoryRevision
            || ! is_string($result['nonce'])
            || preg_match('/^[a-f0-9]{64}$/D', $result['nonce']) !== 1
            || ! is_string($result['raw_measurements_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $result['raw_measurements_sha256']) !== 1
            || ! is_array($result['process'])
            || array_is_list($result['process'])) {
            $this->fail();
        }
        $this->validateProcessResult($result['process'], $measurementCommand);
        $this->validateMeasurements($result['measurements'], $definition['measurement_specs']);
        $rawBytes = CanonicalJson::encode([
            'gate_id' => $definition['gate_id'],
            'repository_revision' => $repositoryRevision,
            'nonce' => $result['nonce'],
            'measurements' => $result['measurements'],
        ])."\n";
        if (! hash_equals($result['raw_measurements_sha256'], hash('sha256', $rawBytes))) {
            $this->fail();
        }

        return [$result['measurements'], $digest];
    }

    private function validateMeasurements(mixed $measurements, array $specs): void
    {
        if (! is_array($measurements) || ! array_is_list($measurements) || count($measurements) !== count($specs)) {
            $this->fail();
        }
        foreach ($specs as $index => [$id, $unit, $limit]) {
            $measurement = $measurements[$index];
            if (! is_array($measurement)
                || array_is_list($measurement)
                || ! $this->hasExactKeys($measurement, ['id', 'value', 'unit', 'limit', 'status'])
                || $measurement['id'] !== $id
                || $measurement['unit'] !== $unit
                || $measurement['limit'] !== $limit
                || $measurement['status'] !== 'passed'
                || ! is_int($measurement['value'])
                || $measurement['value'] < 0
                || $measurement['value'] > $limit) {
                $this->fail();
            }
        }
    }

    private function envelope(
        array $definition,
        string $repositoryRevision,
        array $processResult,
        string $resultArtifactDigest,
        int $tests,
        int $assertions,
        array $records,
        array $measurements,
        ?string $measurementArtifactDigest,
    ): array {
        return [
            'schema_version' => '1.0.0',
            'evidence_scope' => 'ci',
            'artifact_id' => $definition['artifact_id'],
            'artifact_type' => $definition['artifact_type'],
            'repository_revision' => $repositoryRevision,
            'producer' => $definition['producer'],
            'process' => [
                'command' => $processResult['command'],
                'exit_code' => 0,
                'started_at' => $processResult['started_at'],
                'finished_at' => $processResult['finished_at'],
                'duration_ms' => $processResult['duration_ms'],
                'stdout_sha256' => hash('sha256', $processResult['stdout']),
                'stderr_sha256' => hash('sha256', $processResult['stderr']),
                'result_artifact_sha256' => $resultArtifactDigest,
                'measurement_artifact_sha256' => $measurementArtifactDigest,
            ],
            'gate' => [
                'id' => $definition['gate_id'],
                'status' => 'passed',
                'command' => $definition['command'],
                'result' => [
                    'exit_code' => 0,
                    'tests' => $tests,
                    'assertions' => $assertions,
                    'required_checks' => $definition['required_checks'],
                ],
                'duration_ms' => $processResult['duration_ms'],
                'measurements' => $measurements,
            ],
            'records' => $records,
        ];
    }

    private function validateProcessResult(array $processResult, string $command): void
    {
        $this->assertExactKeys($processResult, [
            'command',
            'exit_code',
            'started_at',
            'finished_at',
            'duration_ms',
            'stdout',
            'stderr',
        ]);
        if ($processResult['command'] !== $command
            || $processResult['exit_code'] !== 0
            || ! is_int($processResult['duration_ms'])
            || $processResult['duration_ms'] < 0
            || ! is_string($processResult['stdout'])
            || ! is_string($processResult['stderr'])
            || ! $this->isTimestamp($processResult['started_at'])
            || ! $this->isTimestamp($processResult['finished_at'])
            || ! $this->isOrderedTimeRange($processResult['started_at'], $processResult['finished_at'])) {
            $this->fail();
        }
    }

    private function withoutPath(array $processResult): array
    {
        unset($processResult['path']);

        return $processResult;
    }

    private function readResultArtifact(string $actualPath, string $relativeExpectedPath): array
    {
        $expectedPath = $this->repositoryRoot.'/'.$relativeExpectedPath;
        $resolvedActual = realpath($actualPath);
        $resolvedExpected = realpath($expectedPath);
        if (is_link($actualPath)
            || is_link($expectedPath)
            || ! is_string($resolvedActual)
            || ! is_string($resolvedExpected)
            || strcasecmp(str_replace('\\', '/', $resolvedActual), str_replace('\\', '/', $resolvedExpected)) !== 0
            || ! is_file($resolvedActual)
            || ! $this->isWithinRepository(str_replace('\\', '/', $resolvedActual))) {
            $this->fail();
        }
        $bytes = file_get_contents($resolvedActual);
        if (! is_string($bytes) || $bytes === '') {
            $this->fail();
        }

        return [$bytes, hash('sha256', $bytes)];
    }

    private function relativeExistingPath(string $path): string
    {
        $resolved = realpath($path);
        if (is_link($path) || ! is_string($resolved) || ! is_file($resolved)) {
            $this->fail();
        }
        $normalized = str_replace('\\', '/', $resolved);
        if (! $this->isWithinRepository($normalized)) {
            $this->fail();
        }

        return substr($normalized, strlen($this->repositoryRoot) + 1);
    }

    private function isWithinRepository(string $path): bool
    {
        return strncasecmp(
            $path,
            $this->repositoryRoot.'/',
            strlen($this->repositoryRoot) + 1,
        ) === 0;
    }

    private function integerAttribute(DOMElement $element, string $attribute): int
    {
        $value = $element->getAttribute($attribute);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            $this->fail();
        }
        $integer = (int) $value;
        if ((string) $integer !== $value) {
            $this->fail();
        }

        return $integer;
    }

    private function decode(string $bytes): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail();
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            $this->fail();
        }

        return $decoded;
    }

    private function assertRevision(string $revision): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            $this->fail();
        }
    }

    private function assertExactKeys(array $value, array $expected): void
    {
        if (! $this->hasExactKeys($value, $expected)) {
            $this->fail();
        }
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
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

    private function isOrderedTimeRange(mixed $startedAt, mixed $finishedAt): bool
    {
        if (! is_string($startedAt) || ! is_string($finishedAt)) {
            return false;
        }

        return $startedAt <= $finishedAt;
    }

    private function fail(): never
    {
        throw new InvalidArgumentException('plan_one_b_gate_process_result_invalid');
    }
}
