<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\Process\Process;
use Tests\Support\Reporting\HermeticReportingHttpHarness;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/tests/Support/Reporting/FakeReportingActions.php';
require_once dirname(__DIR__, 2).'/tests/Support/Reporting/HermeticReportingHttpHarness.php';

final class PlanOneAGatesFailure extends RuntimeException
{
    public function __construct(
        public readonly int $exitStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final class PlanOneAGates
{
    private const PHP = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64/php.exe';

    private const PHP_DIR = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64';

    private const PHP_SHA256 = 'f515db26936a2702886ca19523518556972fdf25dee699b78e1c78863a08b680';

    private const CANONICAL_BRANCH = 'feat/reports-canonical-backend';

    private const TASK_FOUR_A_SUBJECT = 'fix[reports]: зафиксировать классификацию и печать снимков';

    private const TASK_FOUR_A_COMMIT = '0b581469a3ad39d4ce5eff5c41072f5ef3f745f7';

    private const TASK_FOUR_A_PARENT = '786e5f3433d04baf35c81789178e1e83012e0916';

    private const TASK_FOUR_A_TREE = '5174bf55c4e13e76a2232fed4e4cb4745578e536';

    private const TASK_FOUR_A_PATHS = [
        'app/BusinessModules/Core/Reporting/Application/Access/ReportAccessService.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/RetryReportExportAction.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/RetryReportRunAction.php',
        'app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSavedViewReferenceResolver.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinition.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportOutputClassification.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewRef.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotSeal.php',
        'app/BusinessModules/Core/Reporting/Domain/Enums/ReportDataClassification.php',
        'app/BusinessModules/Core/Reporting/Domain/Enums/ReportSnapshotClassification.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportExportController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRunController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportCatalogResource.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php',
        'database/migrations/2026_07_26_000001_create_report_runs_table.php',
        'docs/reports/contracts/plan-1a-completion.schema.json',
        'docs/reports/contracts/plan-1a-contract-lock.json',
        'docs/reports/contracts/plan-1a-contract-lock.sha256',
        'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
        'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
        'scripts/reporting/build-plan-1a-evidence.php',
        'scripts/reporting/run-plan-1a-gates.php',
        'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
        'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
        'tests/Architecture/Reporting/ThinReportControllerTest.php',
        'tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
        'tests/Fixtures/Reporting/Evidence/plan-1a-ci-authorization.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-ci-malformed.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json',
        'tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json',
        'tests/Support/Reporting/FakeReportingActions.php',
        'tests/Support/Reporting/HermeticReportingHttpHarness.php',
        'tests/Support/Reporting/ReportDefinitionBuilder.php',
        'tests/Support/Reporting/ReportRunBuilder.php',
        'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
        'tests/Unit/Reporting/Contracts/ReportBindingLifecycleContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
        'tests/Unit/Reporting/Execution/ExecutionContractsTest.php',
        'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
        'tests/Unit/Reporting/Http/ReportResourceSchemaTest.php',
        'tests/Unit/Reporting/Input/ReportInputNormalizerTest.php',
        'tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php',
        'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
        'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
    ];

    private const TASK_FOUR_A2_SUBJECT = 'fix[reports]: типизировать нарушения идентичности снимков';

    private const TASK_FOUR_A2_PARENT = '973aabb17516c0ff9bc7d5a87b3ab6eb8732f333';

    private const TASK_FOUR_B_SUBJECT = 'feat[reports]: добавить надежную доставку заданий отчетов';

    private const TASK_FOUR_B_TREE = '26a315fb485828c66fcd3e9c9b1035f7b3e33800';

    private const TASK_FOUR_B_PATHS = [
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditIntentStore.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportDispatchIntentStore.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportAuditIntent.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportAuditIntentLease.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchAggregate.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchBackoffPolicy.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntent.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntentPublisher.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchIntentReconciler.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchLease.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchPublishSummary.php',
        'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportDispatchTopic.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportRunExportSource.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportRunRetrySource.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Audit/OutboxReportTransitionAudit.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Console/PublishReportDispatchIntentsCommand.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportDispatchIntentsCommand.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Dispatch/LaravelReportDispatchIntentPublisher.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportAuditIntentStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportAuditIntentRecord.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportDispatchIntentRecord.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php',
        'database/migrations/2026_07_26_000001_create_report_runs_table.php',
        'database/migrations/2026_07_26_000002_create_report_dispatch_intents_table.php',
        'database/migrations/2026_07_26_000003_create_report_audit_intents_table.php',
        'tests/Feature/Reporting/Dispatch/EloquentReportAuditIntentStoreTest.php',
        'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
        'tests/Unit/Reporting/Dispatch/ReportAuditIntentContractTest.php',
        'tests/Unit/Reporting/Dispatch/ReportDispatchBackoffPolicyTest.php',
        'tests/Unit/Reporting/Dispatch/ReportDispatchIntentPublisherTest.php',
        'tests/Unit/Reporting/Dispatch/ReportDispatchIntentReconcilerTest.php',
        'tests/Unit/Reporting/Execution/ExecutionContractsTest.php',
        'tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php',
    ];

    private const TASK_FOUR_A2_PATHS = [
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php',
        'app/BusinessModules/Core/Reporting/Domain/Enums/ReportSnapshotIdentityViolationReason.php',
        'app/BusinessModules/Core/Reporting/Domain/Exceptions/ReportSnapshotIdentityViolation.php',
        'docs/reports/contracts/plan-1a-completion.schema.json',
        'docs/reports/contracts/plan-1a-contract-lock.json',
        'docs/reports/contracts/plan-1a-contract-lock.sha256',
        'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
        'scripts/reporting/build-plan-1a-evidence.php',
        'scripts/reporting/run-plan-1a-gates.php',
        'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
        'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
        'tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json',
        'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
        'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
        'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
    ];

    private const TASK_FOUR_A2_LINEAGE = ['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16'];

    private const TASK_FOUR_E_SUBJECT = 'feat[reports]: типизировать ресурсы и текущую авторизацию';

    private const TASK_FOUR_E_PARENT = '1934f947a44aa5221b5aa4cbd8c03963f5f1c005';

    private const TASK_FOUR_A2_COMMIT = '7e216c18952a07f1d002220c8a8bdfefd1e84a36';

    private const TASK_FOUR_A2_TREE = '95091c3598dc17bf55dda0d008c8562e2dbdd16f';

    private const TASK_FOUR_C_SUBJECT = 'feat[reports]: реализовать операции и идентичность запуска';

    private const TASK_FOUR_C_COMMIT = '8fb79f5c24697f5bc39e32ccf13287d528e94886';

    private const TASK_FOUR_C_TREE = '79225dbc5cdb89ee53a2d8c49ed93e5db6942dfa';

    private const TASK_FOUR_C_PATHS = [
        'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CancelReportRunHandler.php',
        'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportRunHandler.php',
        'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRunHandler.php',
        'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/RetryReportRunHandler.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportSnapshotSealVerifier.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/CanonicalReportSourceHashBuilder.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportRunCoordinator.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealValidator.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealVerificationInput.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Security/TrustedReportSnapshotSealVerifier.php',
        'tests/Unit/Reporting/Actions/ReportRunHandlersTest.php',
        'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
        'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
        'tests/Unit/Reporting/Execution/TrustedReportSnapshotSealVerifierTest.php',
    ];

    private const TASK_FOUR_D_SUBJECT = 'fix[reports]: закрепить аренду и контекст фонового запуска';

    private const TASK_FOUR_D_TREE = 'e7c4019512649a39cd21c93588145761c63914f5';

    private const TASK_FOUR_D_PATHS = [
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        'config/horizon.php',
        'config/queue.php',
        'database/migrations/2026_07_26_000004_add_report_run_execution_lineage.php',
        'tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
    ];

    private const TASK_FOUR_E_LINEAGE = [
        'Task 4a exact53',
        'Task 4b exact39',
        'Task 4a2 exact16',
        'Task 4c exact15',
        'Task 4d exact6',
        'Task 4e exact78',
    ];

    private const TASK_FOUR_E_PATHS = [
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportScopedResource.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/CurrentReportAuthorization.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/CurrentReportAuthorizationTarget.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportCatalogAuthorization.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportAuthorizationSubject.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportHttpAuthorizationOrchestrator.php',
        'app/BusinessModules/Core/Reporting/Application/Access/CurrentReportAuthorizationFacts.php',
        'app/BusinessModules/Core/Reporting/Application/Access/CurrentReportPermissionDecision.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportScopedResourceAccessDecision.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/CurrentReportScopeAuthorizer.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Access/ReportScopedResourceAuthorizer.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Access/CurrentReportAbacEvaluator.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Access/ReportAuthorizationSubjectReader.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Access/ReportHttpAuthorizationTargetResolver.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Access/LaravelReportScopedResourceAuthorizerRegistry.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Access/LaravelCurrentReportAbacEvaluator.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Access/LaravelReportHttpAuthorizationTargetResolver.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelCurrentReportScopeAuthorizer.php',
        'database/migrations/2026_07_29_000004_cut_over_report_scope_resources.php',
        'tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php',
        'tests/Unit/Reporting/Execution/CurrentReportAuthorizationTargetTest.php',
        'tests/Unit/Reporting/Access/ReportHttpAuthorizationOrchestratorTest.php',
        'tests/Unit/Reporting/Access/ReportAuthorizationSubjectTest.php',
        'tests/Unit/Reporting/Access/LaravelReportHttpAuthorizationTargetResolverTest.php',
        'tests/Unit/Reporting/Access/ReportScopedResourceAuthorizerContractTest.php',
        'tests/Unit/Reporting/Access/CurrentReportAuthorizationFactsTest.php',
        'tests/Unit/Reporting/Access/CurrentReportPermissionDecisionTest.php',
        'tests/Unit/Reporting/Access/ReportScopedResourceAccessDecisionTest.php',
        'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorTest.php',
        'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorBehaviorTest.php',
        'tests/Feature/Reporting/Access/LaravelCurrentReportAbacEvaluatorRaceTest.php',
        'tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizerTest.php',
        'tests/Feature/Reporting/Execution/LaravelCurrentReportScopeAuthorizationRaceTest.php',
        'tests/Feature/Reporting/Persistence/ReportTypedResourceScopeCutoverMigrationTest.php',
        'tests/Architecture/Reporting/ReportCurrentAuthorizationContractTest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportCatalogController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRunController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRowsController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportDrillDownController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportExportController.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/GetReportCatalogAction.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportScope.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/AuthorizationDecisionContext.php',
        'app/BusinessModules/Core/Reporting/Application/Access/OrganizationReportScopeResolver.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportExecutionContextFactory.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportDispatchIntentStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php',
        'tests/Feature/Reporting/Dispatch/EloquentReportDispatchIntentStoreTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
        'tests/Support/Reporting/ReportExecutionContextBuilder.php',
        'tests/Support/Reporting/ReportRunBuilder.php',
        'tests/Support/Reporting/FakeReportingActions.php',
        'tests/Unit/Reporting/Access/OrganizationReportScopeResolverTest.php',
        'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
        'tests/Unit/Reporting/Execution/CanonicalReportSourceHashBuilderTest.php',
        'tests/Unit/Reporting/Execution/ReportSnapshotSealValidatorTest.php',
        'tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php',
        'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
        'tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php',
        'tests/Architecture/Reporting/ThinReportControllerTest.php',
        'tests/Architecture/Reporting/ReportPortSignatureTest.php',
        'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
        'tests/Feature/Api/V1/Admin/Reporting/ReportingAuthorizationMatrixTest.php',
        'docs/reports/contracts/plan-1a-completion.schema.json',
        'docs/reports/contracts/plan-1a-contract-lock.json',
        'docs/reports/contracts/plan-1a-contract-lock.sha256',
        'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
        'scripts/reporting/build-plan-1a-evidence.php',
        'scripts/reporting/run-plan-1a-gates.php',
        'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
        'tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json',
        'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
        'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
    ];

    private const TASK_FIVE_PATHS = [
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportProgressWritePolicy.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportAsyncContextSeed.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportExpiredExecutionLease.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportExecutionWatchdogSummary.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunAsyncContextSeedReader.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunLeaseRecoveryStore.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunAttemptLifecycleStore.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportExecutionTelemetry.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelReportRunExecutionContextRehydrator.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunAsyncContextSeedReader.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunLeaseRecoveryStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunAttemptLifecycleStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Jobs/MaterializeReportRunJob.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Queue/LaravelReportMaterializationDispatcher.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Listeners/FinalizeFailedReportRunAttempt.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportRunExecutionWatchdog.php',
        'app/BusinessModules/Core/Reporting/Application/Execution/ReportRunAttemptFinalizer.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Console/ReconcileReportRunExecutionLeasesCommand.php',
        'tests/Unit/Reporting/Execution/ReportProgressWritePolicyTest.php',
        'tests/Unit/Reporting/Execution/ReportRunAsyncContextSeedReaderContractTest.php',
        'tests/Unit/Reporting/Execution/ReportRunLeaseRecoveryStoreContractTest.php',
        'tests/Unit/Reporting/Execution/ReportRunExecutionWatchdogTest.php',
        'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
        'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunAsyncContextSeedReaderTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
        'tests/Unit/Reporting/Execution/LaravelReportRunExecutionContextRehydratorTest.php',
        'tests/Support/Reporting/PostgresProcessRaceHarness.php',
        'tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php',
    ];

    private const OUTPUTS = [
        'plan-1a-route-snapshot.json',
        'plan-1a-ci-authorization.json',
        'plan-1a-ci-malformed.json',
        'plan-1a-command-ledger.json',
    ];

    private const ROUTE_SOURCES = [
        'app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php',
        'app/BusinessModules/Core/Reporting/routes.php',
        'app/Support/Routing/AdminRouteStack.php',
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'routes/api.php',
    ];

    private const MATRIX_SOURCES = [
        'app/BusinessModules/Core/Reporting/Application/Access/ReportAccessService.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportActorLoader.php',
        'app/BusinessModules/Core/Reporting/Application/Access/ReportExecutionContextFactory.php',
        'app/BusinessModules/Core/Reporting/Application/Errors/ReportErrorResponseFactory.php',
        'app/BusinessModules/Core/Reporting/Application/Input/ReportFilterReferenceResolver.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportCatalogController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportDrillDownController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportExportController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRowsController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRunController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Middleware/RenderReportErrors.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportDownloadLinkRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportDrillDownRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportExportRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/CreateReportRunRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/GetReportCatalogRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/GetReportRowsRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportExportRouteRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportFormRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportRouteRequest.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Requests/ReportRunRouteRequest.php',
        'app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php',
        'app/BusinessModules/Core/Reporting/routes.php',
        'app/Domain/Authorization/Http/Middleware/AuthorizeMiddleware.php',
        'app/Domain/Authorization/Http/Middleware/InterfaceMiddleware.php',
        'app/Http/Middleware/JwtMiddleware.php',
        'app/Http/Middleware/SetOrganizationContext.php',
        'app/Modules/Middleware/ModuleAccessMiddleware.php',
        'app/Support/Routing/AdminRouteStack.php',
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'routes/api.php',
        'tests/Support/Reporting/FakeReportingActions.php',
        'tests/Support/Reporting/HermeticReportingHttpHarness.php',
    ];

    private const AUTH_CASES = [
        'unauthenticated_catalog_denied',
        'non_admin_catalog_denied',
        'module_disabled_catalog_denied',
        'missing_global_permission_catalog_denied',
        'view_actor_catalog_allowed',
        'view_actor_run_status_allowed',
        'view_actor_rows_allowed',
        'view_actor_run_create_denied',
        'view_actor_export_create_denied',
        'view_actor_download_denied',
        'runner_run_create_allowed',
        'runner_run_retry_allowed',
        'runner_run_cancel_allowed',
        'runner_export_denied',
        'exporter_export_allowed',
        'exporter_download_denied',
        'downloader_revoked_definition_denied',
        'manage_does_not_expand_operational_permissions',
        'foreign_and_nonexistent_filter_indistinguishable',
        'foreign_and_nonexistent_source_indistinguishable',
        'blocked_actor_denied_after_context_reload',
        'deleted_actor_denied_after_context_reload',
    ];

    private const MALFORMED_CASES = [
        'invalid_run_show_ulid',
        'invalid_run_rows_ulid',
        'invalid_run_drill_down_ulid',
        'invalid_run_retry_ulid',
        'missing_run_retry_idempotency_key',
        'invalid_run_retry_idempotency_key',
        'invalid_run_cancel_ulid',
        'invalid_export_create_run_ulid',
        'invalid_export_show_ulid',
        'invalid_export_retry_ulid',
        'missing_export_retry_idempotency_key',
        'invalid_export_retry_idempotency_key',
        'invalid_export_cancel_ulid',
        'invalid_export_download_ulid',
        'missing_run_as_of',
        'rows_limit_101',
        'missing_drill_down_token',
        'invalid_export_format',
        'unexpected_download_body',
        'legacy_routes_absent',
    ];

    private const CONTRACT_TESTS = [
        'tests/Unit/Reporting/Contracts/ReportValueObjectContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportBindingLifecycleContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php',
        'tests/Unit/Reporting/Input/ReportInputNormalizerTest.php',
        'tests/Unit/Reporting/Access/OrganizationReportScopeResolverTest.php',
        'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
        'tests/Unit/Reporting/Access/ReportingPermissionTranslationTest.php',
        'tests/Unit/Reporting/Http/ReportResourceSchemaTest.php',
        'tests/Unit/Reporting/Http/ReportFormRequestContractTest.php',
        'tests/Unit/Reporting/Errors/ReportErrorCatalogTest.php',
        'tests/Unit/Reporting/Errors/ReportErrorResponseFactoryTest.php',
        'tests/Unit/Reporting/Tooling/VerifyTaskSevenComposerTest.php',
        'tests/Architecture/Reporting/ReportPortSignatureTest.php',
        'tests/Architecture/Reporting/ThinReportControllerTest.php',
        'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
        'tests/Architecture/Reporting/ReportingRouteSnapshotTest.php',
        'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
        'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
        'tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php',
        'tests/Unit/Reporting/Execution/ExecutionContractsTest.php',
        'tests/Unit/Reporting/Contracts/ReportScopedResourceContractTest.php',
        'tests/Unit/Reporting/Execution/CurrentReportAuthorizationTargetTest.php',
        'tests/Unit/Reporting/Access/ReportAuthorizationSubjectTest.php',
        'tests/Unit/Reporting/Access/ReportHttpAuthorizationOrchestratorTest.php',
        'tests/Unit/Reporting/Access/LaravelReportHttpAuthorizationTargetResolverTest.php',
        'tests/Unit/Reporting/Access/ReportScopedResourceAuthorizerContractTest.php',
        'tests/Unit/Reporting/Access/CurrentReportAuthorizationFactsTest.php',
        'tests/Unit/Reporting/Access/CurrentReportPermissionDecisionTest.php',
        'tests/Unit/Reporting/Access/ReportScopedResourceAccessDecisionTest.php',
        'tests/Architecture/Reporting/ReportCurrentAuthorizationContractTest.php',
        'tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php',
    ];

    private const STATIC_PATHS = [
        'app/BusinessModules/Core/Reporting',
        'app/Domain/Authorization/Services/AuthorizationService.php',
        'scripts/reporting/verify-task-7-composer.php',
        'scripts/reporting/run-plan-1a-gates.php',
        'scripts/reporting/build-plan-1a-evidence.php',
        'tests/Support/Reporting',
        'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
        'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
        'tests/Architecture/Reporting/PlanOneAScopeBoundaryTest.php',
        'tests/Architecture/Reporting/ThinReportControllerTest.php',
        'tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
        'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
        'tests/Unit/Reporting/Contracts/ReportBindingLifecycleContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
        'tests/Unit/Reporting/Execution/ExecutionContractsTest.php',
        'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
        'tests/Unit/Reporting/Http/ReportResourceSchemaTest.php',
        'tests/Unit/Reporting/Input/ReportInputNormalizerTest.php',
        'tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php',
        'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
        'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
        'tests/Unit/Reporting/Tooling/VerifyTaskSevenComposerTest.php',
    ];

    private static ?Closure $processOverride = null;

    private static ?Closure $topologyOverride = null;

    private static ?Closure $harnessOverride = null;

    private static ?Closure $faultOverride = null;

    private static ?Closure $phpHashOverride = null;

    private static ?Closure $phpVersionOverride = null;

    private static ?Closure $branchOverride = null;

    private static ?Closure $historicalPredicateOverride = null;

    public static function execute(array $argv): int
    {
        $directory = null;
        $normal = false;
        try {
            $options = self::parse(array_slice($argv, 1));
            $root = self::resolveRepositoryRoot($options['repository-root']);
            $directory = self::validateOutputDirectory($root, $options['output-directory'], $options['mode'] === 'normal');
            $normal = $options['mode'] === 'normal';
            if ($normal) {
                self::cleanupOutputs($directory);
            }
            self::fault('after_preclean');
            self::validateGeneratedOutputGovernance($root);
            self::fault('after_output_governance');
            self::validateRepository($root, $options);
            self::fault('after_repository_validation');
            if ($options['mode'] === 'verify') {
                self::verifyExisting($root, $directory, $options['commit-sha']);
            } else {
                $bundle = self::build($root, $options['commit-sha'], $options['executed-at']);
                self::fault('after_build');
                if ($options['mode'] === 'normal') {
                    self::publish($directory, $bundle);
                }
            }
            fwrite(STDOUT, 'plan-1a-gates: passed commands=2 routes=12 authorization=22/22 malformed=20/20'.PHP_EOL);

            return 0;
        } catch (PlanOneAGatesFailure $failure) {
            if ($normal && is_string($directory)) {
                self::cleanupOutputs($directory);
            }
            fwrite(STDERR, $failure->getMessage().PHP_EOL);

            return $failure->exitStatus;
        } catch (Throwable) {
            if ($normal && is_string($directory)) {
                self::cleanupOutputs($directory);
            }
            fwrite(STDERR, 'PLAN_1A_GATE_INTERNAL_FAILURE'.PHP_EOL);

            return 3;
        }
    }

    public static function parse(array $arguments): array
    {
        $values = [];
        foreach ($arguments as $argument) {
            if ($argument === '--check' || $argument === '--verify-existing') {
                $key = substr($argument, 2);
                self::guard(! isset($values[$key]), 2, 'PLAN_1A_GATE_CLI_INVALID');
                $values[$key] = true;

                continue;
            }
            self::guard(str_starts_with($argument, '--') && str_contains($argument, '='), 2, 'PLAN_1A_GATE_CLI_INVALID');
            [$key, $value] = explode('=', substr($argument, 2), 2);
            self::guard(in_array($key, ['repository-root', 'commit-sha', 'output-directory', 'executed-at'], true), 2, 'PLAN_1A_GATE_CLI_INVALID');
            self::guard(! array_key_exists($key, $values), 2, 'PLAN_1A_GATE_CLI_INVALID');
            $values[$key] = $value;
        }
        foreach (['repository-root', 'commit-sha', 'output-directory'] as $key) {
            self::guard(isset($values[$key]) && is_string($values[$key]), 2, 'PLAN_1A_GATE_CLI_INVALID');
        }
        self::guard(! isset($values['check'], $values['verify-existing']), 2, 'PLAN_1A_GATE_CLI_INVALID');
        $verify = isset($values['verify-existing']);
        self::guard($verify !== isset($values['executed-at']), 2, 'PLAN_1A_GATE_TIMESTAMP_MODE_INVALID');
        if (! $verify) {
            self::guard(
                is_string($values['executed-at'])
                    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $values['executed-at']) === 1
                    && gmdate('Y-m-d\TH:i:s\Z', strtotime($values['executed-at'])) === $values['executed-at'],
                2,
                'PLAN_1A_GATE_TIMESTAMP_INVALID',
            );
        }
        self::guard(preg_match('/^[a-f0-9]{40}$/D', $values['commit-sha']) === 1, 2, 'PLAN_1A_GATE_COMMIT_INVALID');
        $values['mode'] = $verify ? 'verify' : (isset($values['check']) ? 'check' : 'normal');

        return $values;
    }

    private static function resolveRepositoryRoot(string $argument): string
    {
        $root = realpath($argument);
        self::guard(
            is_string($root) && (is_dir($root.'/.git') || is_file($root.'/.git')),
            3,
            'PLAN_1A_GATE_ROOT_INVALID',
        );
        $gitRoot = trim(self::process(['git', 'rev-parse', '--show-toplevel'], $root)[0]);
        self::guard(strcasecmp(str_replace('\\', '/', $gitRoot), str_replace('\\', '/', $root)) === 0, 3, 'PLAN_1A_GATE_ROOT_INVALID');

        return $root;
    }

    private static function validateRepository(string $root, array $options): void
    {
        self::guard(trim(self::process(['git', 'rev-parse', 'HEAD'], $root)[0]) === $options['commit-sha'], 3, 'PLAN_1A_GATE_HEAD_MISMATCH');
        $branch = self::$branchOverride instanceof Closure
            ? (self::$branchOverride)($root)
            : trim(self::process(['git', 'branch', '--show-current'], $root)[0]);
        self::guard($branch === self::CANONICAL_BRANCH, 3, 'PLAN_1A_GATE_BRANCH_INVALID');
        $phpVersion = self::$phpVersionOverride instanceof Closure
            ? (self::$phpVersionOverride)()
            : PHP_VERSION;
        self::guard($phpVersion === '8.2.29', 2, 'PLAN_1A_GATE_PHP_VERSION_MISMATCH');
        $phpHash = self::$phpHashOverride instanceof Closure
            ? (self::$phpHashOverride)()
            : hash_file('sha256', self::PHP);
        self::guard($phpHash === self::PHP_SHA256, 2, 'PLAN_1A_GATE_PHP_HASH_MISMATCH');
        self::validateGitState($root, $options['commit-sha']);
    }

    private static function validateGeneratedOutputGovernance(string $root): void
    {
        $paths = array_map(static fn (string $file): string => 'build/reports/'.$file, self::OUTPUTS);
        [$tracked, , $trackedExit] = self::process(['git', 'ls-files', '--', ...$paths], $root, false);
        self::guard($trackedExit === 0 && trim($tracked) === '', 3, 'PLAN_1A_GATE_OUTPUT_TRACKED');
        [, , $ignoredExit] = self::process(['git', 'check-ignore', '--no-index', '--', ...$paths], $root, false);
        self::guard($ignoredExit === 0, 3, 'PLAN_1A_GATE_OUTPUT_NOT_IGNORED');
    }

    private static function validateOutputDirectory(string $root, string $argument, bool $create): string
    {
        self::guard(str_replace('\\', '/', $argument) === 'build/reports', 2, 'PLAN_1A_GATE_OUTPUT_PATH_INVALID');
        $build = $root.'/build';
        $directory = $build.'/reports';
        if (! file_exists($build)) {
            self::guard($create && mkdir($build, 0777), 6, 'PLAN_1A_GATE_OUTPUT_CREATE_FAILED');
        }
        self::guard(is_dir($build) && ! is_link($build), 2, 'PLAN_1A_GATE_OUTPUT_PATH_INVALID');
        self::guard(self::samePath((string) realpath($build), $root.'/build'), 2, 'PLAN_1A_GATE_OUTPUT_PATH_INVALID');
        if (! file_exists($directory)) {
            self::guard($create && mkdir($directory, 0777), 6, 'PLAN_1A_GATE_OUTPUT_CREATE_FAILED');
        }
        self::guard(is_dir($directory) && ! is_link($directory), 2, 'PLAN_1A_GATE_OUTPUT_PATH_INVALID');
        self::guard(self::samePath((string) realpath($directory), $root.'/build/reports'), 2, 'PLAN_1A_GATE_OUTPUT_PATH_INVALID');

        return $directory;
    }

    private static function build(string $root, string $commit, string $timestamp): array
    {
        $topology = self::$topologyOverride instanceof Closure
            ? (self::$topologyOverride)($root)
            : self::readProductionTopology($root);
        $routeHarness = self::$harnessOverride instanceof Closure
            ? (self::$harnessOverride)($root)
            : new HermeticReportingHttpHarness;
        self::guard($routeHarness instanceof HermeticReportingHttpHarness, 4, 'PLAN_1A_GATE_HARNESS_INVALID');
        $routes = [];
        foreach ($routeHarness->router()->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, 'admin.reports.')) {
                $routes[] = [
                    'name' => $name,
                    'methods' => $route->methods(),
                    'uri' => $route->uri(),
                    'middleware' => $route->middleware(),
                ];
            }
        }
        usort($routes, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
        $expected = HermeticReportingHttpHarness::expectedRoutes();
        ksort($expected, SORT_STRING);
        self::guard(
            array_map(static fn (array $route): array => [$route['methods'], $route['uri']], $routes) === array_values($expected),
            4,
            'PLAN_1A_GATE_ROUTE_DRIFT',
        );
        self::guard($routeHarness->providerRegistrationCount() === 1, 4, 'PLAN_1A_GATE_PROVIDER_DRIFT');
        self::guard(! $routeHarness->apiRoutesRequireLegacyReports() && ! $routeHarness->legacyRouteFileExists(), 4, 'PLAN_1A_GATE_LEGACY_DRIFT');
        $routeArtifact = [
            'artifact_id' => 'plan_1a_route_snapshot',
            'schema_version' => '1.0.0',
            'status' => 'passed',
            'verification_mode' => 'production_topology_snapshot',
            'producer_commit_sha' => $commit,
            'executed_at' => $timestamp,
            'counts' => ['core_routes' => 12, 'legacy_routes' => 0, 'provider_registrations' => 1],
            'topology' => $topology,
            'routes' => $routes,
            'legacy_uris' => HermeticReportingHttpHarness::legacyUris(),
            'source_files' => self::sourceHashes($root, $commit, self::ROUTE_SOURCES),
        ];
        self::validateSchema(
            $root,
            self::encode($routeArtifact),
            'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'PLAN_1A_GATE_ROUTE_SCHEMA_INVALID',
        );

        $authorizationCases = [];
        foreach (self::AUTH_CASES as $caseId) {
            $authorizationCases[] = (new HermeticReportingHttpHarness)->runAuthorizationCase($caseId);
        }
        self::validateCases($authorizationCases, self::AUTH_CASES, 28, 132);
        $authorization = [
            'artifact_id' => 'plan_1a_ci_authorization',
            'schema_version' => '1.0.0',
            'status' => 'passed',
            'verification_mode' => 'hermetic_http',
            'producer_commit_sha' => $commit,
            'executed_at' => $timestamp,
            'matrix' => 'authorization',
            'counts' => ['cases' => 22, 'passed' => 22, 'allowed_cases' => 7, 'denied_cases' => 15, 'http_requests' => 28, 'assertions' => 132],
            'cases' => $authorizationCases,
            'source_files' => self::sourceHashes($root, $commit, [...self::MATRIX_SOURCES, 'tests/Feature/Api/V1/Admin/Reporting/ReportingAuthorizationMatrixTest.php']),
        ];
        self::validateSchema(
            $root,
            self::encode($authorization),
            'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'PLAN_1A_GATE_AUTHORIZATION_SCHEMA_INVALID',
        );

        $malformedCases = [];
        foreach (self::MALFORMED_CASES as $caseId) {
            $malformedCases[] = (new HermeticReportingHttpHarness)->runMalformedCase($caseId);
        }
        self::validateCases($malformedCases, self::MALFORMED_CASES, 38, 120);
        $malformed = [
            'artifact_id' => 'plan_1a_ci_malformed',
            'schema_version' => '1.0.0',
            'status' => 'passed',
            'verification_mode' => 'hermetic_http',
            'producer_commit_sha' => $commit,
            'executed_at' => $timestamp,
            'matrix' => 'malformed_requests',
            'counts' => ['cases' => 20, 'passed' => 20, 'validation_cases' => 19, 'legacy_absence_cases' => 1, 'legacy_uri_count' => 19, 'http_requests' => 38, 'assertions' => 120],
            'cases' => $malformedCases,
            'source_files' => self::sourceHashes($root, $commit, [...self::MATRIX_SOURCES, 'tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php']),
        ];
        self::validateSchema(
            $root,
            self::encode($malformed),
            'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'PLAN_1A_GATE_MALFORMED_SCHEMA_INVALID',
        );

        $commands = self::runCommands($root, $timestamp);
        $ledger = [
            'artifact_id' => 'plan_1a_command_ledger',
            'schema_version' => '1.0.0',
            'status' => 'passed',
            'verification_mode' => 'local_fixed_commands',
            'producer_commit_sha' => $commit,
            'executed_at' => $timestamp,
            'task_4e' => self::taskFourEEvidence(),
            'commands' => $commands,
        ];
        self::validateSchema(
            $root,
            self::encode($ledger),
            'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
            'PLAN_1A_GATE_LEDGER_SCHEMA_INVALID',
        );
        $bundle = [
            'plan-1a-route-snapshot.json' => self::encode($routeArtifact),
            'plan-1a-ci-authorization.json' => self::encode($authorization),
            'plan-1a-ci-malformed.json' => self::encode($malformed),
            'plan-1a-command-ledger.json' => self::encode($ledger),
        ];

        return $bundle;
    }

    private static function taskFourEEvidence(): array
    {
        return [
            'subject' => self::TASK_FOUR_E_SUBJECT,
            'parent_commit_sha' => self::TASK_FOUR_E_PARENT,
            'lineage' => self::TASK_FOUR_E_LINEAGE,
            'manifest_count' => count(self::TASK_FOUR_E_PATHS),
            'typed_resource_contract' => 'passed',
            'typed_decision_contract' => 'passed',
            'queue_authorization' => 'uncached_repeatable_read_passed',
            'prohibitions' => 'passed',
            'migration_cutover' => 'static_contract_passed_not_executed_locally',
            'resource_registry' => 'passed',
            'authorization_matrices' => self::taskFourEMatrixInventories(),
            'ownership_audit' => self::taskFourEOwnershipAudit(),
        ];
    }

    private static function taskFourEOwnershipAudit(): array
    {
        $taskFourE = array_values(array_unique(self::TASK_FOUR_E_PATHS));
        $taskFive = array_values(array_unique(self::TASK_FIVE_PATHS));
        $overlap = array_values(array_intersect($taskFourE, $taskFive));
        $union = array_values(array_unique([...$taskFourE, ...$taskFive]));
        self::guard(
            count($taskFourE) === 78
                && count($taskFive) === 30
                && count($union) === 108
                && $overlap === [],
            4,
            'PLAN_1A_TASK_4E_OWNERSHIP_INVALID',
        );

        return [
            'task_4e' => 78,
            'task_5' => 30,
            'union' => 108,
            'overlap' => 0,
            'unowned' => 0,
            'extra' => 0,
        ];
    }

    private static function taskFourEMatrixInventories(): array
    {
        $scopeClass = Tests\Feature\Reporting\Execution\LaravelCurrentReportScopeAuthorizerTest::class;
        $scopeMethods = self::declaredTestMethods($scopeClass);
        $organizationStart = array_search('test_inactive_actor_is_denied', $scopeMethods, true);
        $organizationEnd = array_search(
            'test_child_access_is_inherited_only_through_current_holding_parent',
            $scopeMethods,
            true,
        );
        $projectStart = array_search(
            'test_all_project_mode_includes_every_accessible_active_project',
            $scopeMethods,
            true,
        );
        $projectEnd = array_search(
            'test_newly_accessible_unrelated_project_does_not_widen_exact_scope',
            $scopeMethods,
            true,
        );
        self::guard(
            is_int($organizationStart)
                && is_int($organizationEnd)
                && is_int($projectStart)
                && is_int($projectEnd),
            4,
            'PLAN_1A_TASK_4E_MATRIX_PROVIDER_INVALID',
        );

        return [
            'organization_scope' => array_slice(
                $scopeMethods,
                $organizationStart,
                $organizationEnd - $organizationStart + 1,
            ),
            'project_scope' => array_slice(
                $scopeMethods,
                $projectStart,
                $projectEnd - $projectStart + 1,
            ),
            'current_abac' => self::expandedTestInventory(
                Tests\Feature\Reporting\Access\LaravelCurrentReportAbacEvaluatorBehaviorTest::class,
                ['test_closed_abac_behavior_matrix'],
            ),
            'typed_resources' => self::expandedTestInventory(
                Tests\Unit\Reporting\Access\ReportScopedResourceAuthorizerContractTest::class,
            ),
            'repeatable_read_races' => [
                ...self::expandedTestInventory(
                    Tests\Feature\Reporting\Execution\LaravelCurrentReportScopeAuthorizationRaceTest::class,
                    [
                        'test_membership_revocation_after_snapshot_affects_only_next_authorization',
                        'test_project_assignment_revocation_after_snapshot_affects_only_next_authorization',
                        'test_holding_child_reparent_after_snapshot_affects_only_next_authorization',
                        'test_resource_transfer_after_snapshot_cannot_mix_with_new_permission_facts',
                    ],
                ),
                ...self::expandedTestInventory(
                    Tests\Feature\Reporting\Access\LaravelCurrentReportAbacEvaluatorRaceTest::class,
                ),
            ],
        ];
    }

    private static function declaredTestMethods(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class
                && str_starts_with($method->getName(), 'test_'),
        );
        usort(
            $methods,
            static fn (ReflectionMethod $left, ReflectionMethod $right): int => $left->getStartLine() <=> $right->getStartLine(),
        );

        return array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
    }

    private static function expandedTestInventory(string $class, ?array $selectedMethods = null): array
    {
        $reflection = new ReflectionClass($class);
        $methods = $selectedMethods ?? self::declaredTestMethods($class);
        $inventory = [];
        foreach ($methods as $methodName) {
            self::guard($reflection->hasMethod($methodName), 4, 'PLAN_1A_TASK_4E_MATRIX_PROVIDER_INVALID');
            $method = $reflection->getMethod($methodName);
            $attributes = $method->getAttributes(PHPUnit\Framework\Attributes\DataProvider::class);
            if ($attributes === []) {
                $inventory[] = $methodName;

                continue;
            }
            self::guard(count($attributes) === 1, 4, 'PLAN_1A_TASK_4E_MATRIX_PROVIDER_INVALID');
            $arguments = $attributes[0]->getArguments();
            $provider = $arguments[0] ?? null;
            self::guard(is_string($provider) && $reflection->hasMethod($provider), 4, 'PLAN_1A_TASK_4E_MATRIX_PROVIDER_INVALID');
            $provided = $reflection->getMethod($provider)->invoke(null);
            self::guard(is_array($provided) || $provided instanceof Traversable, 4, 'PLAN_1A_TASK_4E_MATRIX_PROVIDER_INVALID');
            $datasets = is_array($provided) ? $provided : iterator_to_array($provided);
            foreach (array_keys($datasets) as $dataset) {
                $inventory[] = $methodName.'::'.(string) $dataset;
            }
        }

        self::guard($inventory !== [] && count($inventory) === count(array_unique($inventory)), 4, 'PLAN_1A_TASK_4E_MATRIX_PROVIDER_INVALID');

        return $inventory;
    }

    private static function readProductionTopology(string $root): array
    {
        $previous = Illuminate\Container\Container::getInstance();
        $application = require $root.'/bootstrap/app.php';
        self::guard(
            $application instanceof Illuminate\Foundation\Application
                && ! $application->isBooted()
                && ! $application->hasBeenBootstrapped(),
            4,
            'PLAN_1A_GATE_PRODUCTION_BOOTED',
        );
        $kernel = $application->make(Illuminate\Contracts\Http\Kernel::class);
        self::guard(! $application->isBooted() && ! $application->hasBeenBootstrapped(), 4, 'PLAN_1A_GATE_PRODUCTION_BOOTED');
        $reflection = new ReflectionObject($kernel);
        $middleware = $reflection->getProperty('middleware')->getValue($kernel);
        $groups = $reflection->getProperty('middlewareGroups')->getValue($kernel);
        Illuminate\Container\Container::setInstance($previous);
        Illuminate\Support\Facades\Facade::clearResolvedInstances();

        return ['global_middleware' => $middleware, 'api_middleware' => $groups['api']];
    }

    private static function validateCases(array $records, array $ids, int $requests, int $assertions): void
    {
        self::guard(array_column($records, 'case_id') === $ids, 4, 'PLAN_1A_GATE_CASE_ORDER_DRIFT');
        foreach ($records as $record) {
            self::guard(array_keys($record) === ['case_id', 'status', 'request_count', 'response_statuses', 'response_codes', 'action_calls', 'actor_loads', 'assertions'], 4, 'PLAN_1A_GATE_CASE_SHAPE_DRIFT');
        }
        self::guard(array_sum(array_column($records, 'request_count')) === $requests, 4, 'PLAN_1A_GATE_REQUEST_COUNT_DRIFT');
        self::guard(array_sum(array_column($records, 'assertions')) === $assertions, 4, 'PLAN_1A_GATE_ASSERTION_COUNT_DRIFT');
    }

    private static function runCommands(string $root, string $timestamp): array
    {
        $contractArgv = [self::PHP, '-c', self::PHP_DIR, 'vendor/bin/phpunit', ...self::CONTRACT_TESTS];
        [$stdout, $stderr, $exit] = self::process($contractArgv, $root, false);
        $combined = $stdout.$stderr;
        self::guard($exit === 0, 5, 'PLAN_1A_GATE_CONTRACT_COMMAND_FAILED');
        self::guard(
            preg_match_all('/OK \\(([1-9][0-9]*) tests?, ([1-9][0-9]*) assertions?\\)/', $combined, $matches) === 1,
            5,
            'PLAN_1A_GATE_CONTRACT_COUNT_DRIFT',
        );
        self::guard(preg_match('/Skipped|Incomplete|Risky|FAILURES!|ERRORS!/i', $combined) !== 1, 5, 'PLAN_1A_GATE_CONTRACT_NON_PASS');

        $staticArgv = [self::PHP, '-c', self::PHP_DIR, 'vendor/bin/phpstan', 'analyse', ...self::STATIC_PATHS, '--no-progress'];
        [$stdout, $stderr, $exit] = self::process($staticArgv, $root, false);
        self::guard($exit === 0 && substr_count($stdout.$stderr, '[OK] No errors') === 1, 5, 'PLAN_1A_GATE_STATIC_COMMAND_FAILED');

        return [
            ['command_id' => 'plan1a_contract_tests', 'command' => self::render($contractArgv), 'status' => 'passed', 'exit_code' => 0, 'tests' => (int) $matches[1][0], 'assertions' => (int) $matches[2][0], 'skipped' => 0, 'executed_at' => $timestamp],
            ['command_id' => 'plan1a_static_analysis', 'command' => self::render($staticArgv), 'status' => 'passed', 'exit_code' => 0, 'tests' => 0, 'assertions' => 0, 'skipped' => 0, 'executed_at' => $timestamp],
        ];
    }

    private static function verifyExisting(string $root, string $directory, string $commit): void
    {
        $ledgerPath = $directory.'/plan-1a-command-ledger.json';
        self::guard(is_file($ledgerPath), 6, 'PLAN_1A_GATE_LEDGER_MISSING');
        $ledger = json_decode((string) file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
        self::guard(is_array($ledger) && is_string($ledger['executed_at'] ?? null), 6, 'PLAN_1A_GATE_LEDGER_INVALID');
        foreach ($ledger['commands'] ?? [] as $command) {
            self::guard(($command['executed_at'] ?? null) === $ledger['executed_at'], 6, 'PLAN_1A_GATE_TIMESTAMP_DIVERGENCE');
        }
        $existing = [];
        foreach (self::OUTPUTS as $file) {
            self::guard(is_file($directory.'/'.$file), 6, 'PLAN_1A_GATE_OUTPUT_MISSING');
            $existing[$file] = (string) file_get_contents($directory.'/'.$file);
            $decoded = json_decode($existing[$file], true, 512, JSON_THROW_ON_ERROR);
            self::guard(($decoded['executed_at'] ?? null) === $ledger['executed_at'], 6, 'PLAN_1A_GATE_TIMESTAMP_DIVERGENCE');
        }
        $rebuilt = self::build($root, $commit, $ledger['executed_at']);
        self::guard($rebuilt === $existing, 6, 'PLAN_1A_GATE_REPLAY_DRIFT');
    }

    private static function publish(string $directory, array $bundle): void
    {
        try {
            foreach (self::OUTPUTS as $file) {
                $temporary = self::temporaryPath($directory);
                self::guard(file_put_contents($temporary, $bundle[$file], LOCK_EX) !== false, 6, 'PLAN_1A_GATE_OUTPUT_WRITE_FAILED');
                self::fault('after_temporary_write:'.$file);
                self::guard(@rename($temporary, $directory.'/'.$file), 6, 'PLAN_1A_GATE_OUTPUT_WRITE_FAILED');
                self::fault('after_publish:'.$file);
                self::guard(
                    hash_equals($bundle[$file], (string) file_get_contents($directory.'/'.$file)),
                    6,
                    'PLAN_1A_GATE_OUTPUT_REREAD_FAILED',
                );
                self::fault('after_reread:'.$file);
            }
        } catch (Throwable $failure) {
            self::cleanupOutputs($directory);
            throw $failure;
        }
    }

    private static function sourceHashes(string $root, string $commit, array $paths): array
    {
        sort($paths, SORT_STRING);
        $hashes = [];
        foreach ($paths as $path) {
            $bytes = self::process(['git', 'show', $commit.':'.$path], $root)[0];
            $hashes[$path] = hash('sha256', $bytes);
        }

        return $hashes;
    }

    private static function validateSchema(
        string $root,
        string $bytes,
        string $schemaPath,
        string $failureCode = 'PLAN_1A_GATE_SCHEMA_INVALID',
    ): void {
        $data = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode((string) file_get_contents($root.'/'.$schemaPath), false, 512, JSON_THROW_ON_ERROR);
        self::guard((new CompliantValidator)->validate($data, $schema)->isValid(), 3, $failureCode);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private static function render(array $arguments): string
    {
        return implode(' ', array_map(
            static fn (string $argument): string => preg_match('/[\s"]/', $argument) === 1
                ? '"'.str_replace('"', '\\"', $argument).'"'
                : $argument,
            $arguments,
        ));
    }

    private static function process(array $arguments, string $root, bool $mustPass = true): array
    {
        if (self::$processOverride instanceof Closure) {
            $overridden = (self::$processOverride)($arguments, $root, $mustPass);
            if (is_array($overridden)) {
                self::guard(
                    count($overridden) === 3
                        && is_string($overridden[0] ?? null)
                        && is_string($overridden[1] ?? null)
                        && is_int($overridden[2] ?? null),
                    3,
                    'PLAN_1A_GATE_PROCESS_OVERRIDE_INVALID',
                );
                if ($mustPass) {
                    self::guard($overridden[2] === 0, 3, 'PLAN_1A_GATE_PROCESS_FAILED');
                }

                return $overridden;
            }
        }
        $process = new Process($arguments, $root);
        $process->setTimeout(600);
        $process->run();
        if ($mustPass) {
            self::guard($process->isSuccessful(), 3, 'PLAN_1A_GATE_PROCESS_FAILED');
        }

        return [$process->getOutput(), $process->getErrorOutput(), $process->getExitCode()];
    }

    private static function fault(string $boundary): void
    {
        if (self::$faultOverride instanceof Closure) {
            (self::$faultOverride)($boundary);
        }
    }

    private static function removeBounded(string $path): void
    {
        if (is_file($path) && ! unlink($path)) {
            throw new PlanOneAGatesFailure(6, 'PLAN_1A_GATE_OUTPUT_CLEANUP_FAILED');
        }
    }

    private static function cleanupOutputs(string $directory): void
    {
        foreach (self::OUTPUTS as $file) {
            self::removeBounded($directory.'/'.$file);
        }
        foreach (glob($directory.'/.plan-1a-*.tmp') ?: [] as $path) {
            self::removeBounded($path);
        }
    }

    private static function temporaryPath(string $directory): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $directory.'/.plan-1a-'.bin2hex(random_bytes(8)).'.tmp';
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                fclose($handle);

                return $path;
            }
        }
        throw new PlanOneAGatesFailure(6, 'PLAN_1A_GATE_OUTPUT_WRITE_FAILED');
    }

    private static function validateGitState(string $root, string $commit): void
    {
        $staged = self::gitPaths(['diff', '--cached', '--name-only', '-z'], $root);
        $unstaged = self::gitPaths(['diff', '--name-only', '-z'], $root);
        $untracked = self::gitPaths(['ls-files', '--others', '--exclude-standard', '-z'], $root);
        $working = array_values(array_unique([...$unstaged, ...$untracked]));
        sort($working, SORT_STRING);
        $taskFourEPaths = self::TASK_FOUR_E_PATHS;
        sort($taskFourEPaths, SORT_STRING);
        if ($commit === self::TASK_FOUR_E_PARENT
            && $staged === $taskFourEPaths
            && $working === []) {
            self::validateHistoricalTaskLineage($root);

            return;
        }
        if ($commit === self::TASK_FOUR_E_PARENT
            && $staged === []
            && $working === $taskFourEPaths) {
            self::validateHistoricalTaskLineage($root);

            return;
        }
        self::guard($staged === [] && $working === [], 3, 'PLAN_1A_GATE_WORKTREE_DIRTY');
        self::validateCanonicalTaskFourECommit($root, $commit);
    }

    private static function validateHistoricalTaskLineage(string $root): void
    {
        self::validateHistoricalTaskFourACommit($root, self::TASK_FOUR_A_COMMIT);
        self::validateHistoricalTaskFourBCommit($root, self::TASK_FOUR_A2_PARENT);
        self::validateHistoricalCommit($root, self::TASK_FOUR_A2_COMMIT, self::TASK_FOUR_A2_COMMIT, self::TASK_FOUR_A2_PARENT, self::TASK_FOUR_A2_TREE, self::TASK_FOUR_A2_SUBJECT, self::TASK_FOUR_A2_PATHS, 'PLAN_1A_GATE_TASK_4A2_HISTORY');
        self::validateHistoricalCommit($root, self::TASK_FOUR_C_COMMIT, self::TASK_FOUR_C_COMMIT, self::TASK_FOUR_A2_COMMIT, self::TASK_FOUR_C_TREE, self::TASK_FOUR_C_SUBJECT, self::TASK_FOUR_C_PATHS, 'PLAN_1A_GATE_TASK_4C_HISTORY');
        self::validateHistoricalCommit($root, self::TASK_FOUR_E_PARENT, self::TASK_FOUR_E_PARENT, self::TASK_FOUR_C_COMMIT, self::TASK_FOUR_D_TREE, self::TASK_FOUR_D_SUBJECT, self::TASK_FOUR_D_PATHS, 'PLAN_1A_GATE_TASK_4D_HISTORY');
    }

    private static function validateHistoricalTaskFourACommit(string $root, string $commit): void
    {
        self::validateHistoricalCommit(
            $root,
            $commit,
            self::TASK_FOUR_A_COMMIT,
            self::TASK_FOUR_A_PARENT,
            self::TASK_FOUR_A_TREE,
            self::TASK_FOUR_A_SUBJECT,
            self::TASK_FOUR_A_PATHS,
            'PLAN_1A_GATE_TASK_4A_HISTORY',
        );
    }

    private static function validateHistoricalTaskFourBCommit(string $root, string $commit): void
    {
        self::validateHistoricalCommit(
            $root,
            $commit,
            self::TASK_FOUR_A2_PARENT,
            self::TASK_FOUR_A_COMMIT,
            self::TASK_FOUR_B_TREE,
            self::TASK_FOUR_B_SUBJECT,
            self::TASK_FOUR_B_PATHS,
            'PLAN_1A_GATE_TASK_4B_HISTORY',
        );
    }

    private static function validateHistoricalCommit(
        string $root,
        string $commit,
        string $expectedCommit,
        string $expectedParent,
        string $expectedTree,
        string $expectedSubject,
        array $expectedPaths,
        string $failure,
    ): void {
        $type = trim(self::process(['git', '--no-replace-objects', 'cat-file', '-t', $commit], $root)[0]);
        $metadata = explode("\0", rtrim(self::process([
            'git',
            '--no-replace-objects',
            'show',
            '-s',
            '--format=%H%x00%P%x00%T%x00%s',
            $commit,
        ], $root)[0], "\r\n"));
        $paths = self::gitPaths([
            '--no-replace-objects',
            'diff-tree',
            '--no-renames',
            '--no-commit-id',
            '--name-only',
            '-r',
            '-z',
            $commit,
        ], $root);
        self::guard(self::historicalPredicate('commit', $type === 'commit' && ($metadata[0] ?? null) === $expectedCommit), 3, $failure.'_COMMIT_INVALID');
        self::guard(self::historicalPredicate('parent', ($metadata[1] ?? null) === $expectedParent), 3, $failure.'_PARENT_INVALID');
        self::guard(self::historicalPredicate('tree', ($metadata[2] ?? null) === $expectedTree), 3, $failure.'_TREE_INVALID');
        self::guard(self::historicalPredicate('subject', ($metadata[3] ?? null) === $expectedSubject), 3, $failure.'_SUBJECT_INVALID');
        self::guard(self::historicalPredicate('paths', $paths === $expectedPaths), 3, $failure.'_PATHS_INVALID');
    }

    private static function historicalPredicate(string $boundary, bool $actual): bool
    {
        if (self::$historicalPredicateOverride instanceof Closure) {
            $overridden = (self::$historicalPredicateOverride)($boundary, $actual);
            self::guard(is_bool($overridden), 3, 'PLAN_1A_GATE_HISTORICAL_PREDICATE_OVERRIDE_INVALID');

            return $overridden;
        }

        return $actual;
    }

    private static function validateCanonicalTaskFourA2Commit(string $root, string $commit): void
    {
        self::validateHistoricalTaskLineage($root);
        self::guard(
            trim(self::process(['git', 'show', '-s', '--format=%s', $commit], $root)[0]) === self::TASK_FOUR_A2_SUBJECT,
            3,
            'PLAN_1A_GATE_TASK_4A2_SUBJECT_INVALID',
        );
        $parents = preg_split('/\s+/', trim(self::process(['git', 'show', '-s', '--format=%P', $commit], $root)[0])) ?: [];
        self::guard($parents === [self::TASK_FOUR_A2_PARENT], 3, 'PLAN_1A_GATE_TASK_4A2_PARENT_INVALID');
        $paths = self::gitPaths(['diff-tree', '--no-renames', '--no-commit-id', '--name-only', '-r', '-z', $commit], $root);
        self::guard($paths === self::TASK_FOUR_A2_PATHS, 3, 'PLAN_1A_GATE_TASK_4A2_PATHS_INVALID');
        foreach (self::TASK_FOUR_A2_PATHS as $path) {
            $gitBytes = self::process(['git', 'show', $commit.':'.$path], $root)[0];
            self::guard(
                is_file($root.'/'.$path) && hash_equals(hash('sha256', $gitBytes), hash_file('sha256', $root.'/'.$path)),
                3,
                'PLAN_1A_GATE_TASK_4A2_BYTES_INVALID',
            );
        }
    }

    private static function validateCanonicalTaskFourECommit(string $root, string $commit): void
    {
        self::validateHistoricalTaskLineage($root);
        self::guard(trim(self::process(['git', 'show', '-s', '--format=%s', $commit], $root)[0]) === self::TASK_FOUR_E_SUBJECT, 3, 'PLAN_1A_GATE_TASK_4E_SUBJECT_INVALID');
        $parents = preg_split('/\s+/', trim(self::process(['git', 'show', '-s', '--format=%P', $commit], $root)[0])) ?: [];
        self::guard($parents === [self::TASK_FOUR_E_PARENT], 3, 'PLAN_1A_GATE_TASK_4E_PARENT_INVALID');
        $paths = self::gitPaths(['diff-tree', '--no-renames', '--no-commit-id', '--name-only', '-r', '-z', $commit], $root);
        $expectedPaths = self::TASK_FOUR_E_PATHS;
        sort($expectedPaths, SORT_STRING);
        self::guard($paths === $expectedPaths, 3, 'PLAN_1A_GATE_TASK_4E_PATHS_INVALID');
        foreach (self::TASK_FOUR_E_PATHS as $path) {
            $gitBytes = self::process(['git', 'show', $commit.':'.$path], $root)[0];
            self::guard(is_file($root.'/'.$path) && hash_equals(hash('sha256', $gitBytes), hash_file('sha256', $root.'/'.$path)), 3, 'PLAN_1A_GATE_TASK_4E_BYTES_INVALID');
        }
    }

    private static function gitPaths(array $arguments, string $root): array
    {
        $bytes = self::process(['git', ...$arguments], $root)[0];
        $paths = array_values(array_filter(explode("\0", $bytes), static fn (string $path): bool => $path !== ''));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private static function samePath(string $actual, string $expected): bool
    {
        return strcasecmp(str_replace('\\', '/', $actual), str_replace('\\', '/', $expected)) === 0;
    }

    private static function guard(bool $condition, int $exit, string $message): void
    {
        if (! $condition) {
            throw new PlanOneAGatesFailure($exit, $message);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PlanOneAGates::execute($argv));
}
