<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Support\Architecture\ContractMutationAstScanner;

final class ContractAuditMutationCoverageTest extends TestCase
{
    public function test_runtime_contract_mutations_are_confined_to_audited_boundary(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $exemptions = [
            'ContractAuditedMutationService|persistUpdate|update|$contract' => 1,
            'ContractAuditedMutationService|delete|delete|$contract' => 1,
            'ContractSideMutationService|create|create|$this->contractRepository' => 1,
            "SetupRBACTestEnvironment|cleanupTestData|delete|\\Illuminate\\Support\\Facades\\DB::table('contracts')->whereIn('organization_id',\$orgIds)" => 1,
            "SetupRBACTestEnvironment|cleanupTestData|delete|\\Illuminate\\Support\\Facades\\DB::table('contracts')->whereIn('project_id',\$projectIds)" => 1,
        ];
        $structuralExemptions = [];
        foreach (array_filter(explode("\n", <<<'MANIFEST'
AiBudgetGuard|claimWire|ede0defd785dc44f89f64917716e4949327f3977e59acc2730617cedf6660cdf=1
AiBudgetGuard|pendingReconciliation|a8779b8d933ebcc2054b4954313a16f98ffffab7844eaa269164d09d776b215e=1
AiBudgetGuard|reconcileExpired|56f116f65fe40f264e4a3b08fa9e58ef7adbf15a86ba48e37634e7af25b1d93a=1
AiBudgetGuard|releaseBeforeWire|74859b8de0150d17a423383407711763e168e213d71bf93cec31b73d3dace8f8=1
AiBudgetGuard|reserve|688fbf673185880e1a75ee2490423511b880917d687d9b1a98d4474a4d071547=1
AiBudgetGuard|settle|a17c2da1eb96472ee99982511554c1eae6be74ff1c2b900a16dc1047a3815f89=1
BuildSessionOperationalSnapshot|finalization|1372ddea090f4f880fb7bad207e235f663af14c5bdc236660b0d91fd677dd8e0=1
BuildSessionOperationalSnapshot|sourceWatermarks|cb3b03ba0fda763cff07dca0d020f010bd9c32f9ba1e5043fb6adc210e723ce5=1
ContractService|getContractsSummary|431440fbfed0ac56734c38126089b6f4b9014dbf5b6fe40d8804b91e0f50d3af=1
DashboardController|contractsRequiringAttention|68e221711930892ac959d7370ba9f8bc856ccda312bd9745f44225eaa69358fa=1
EloquentEffectiveSettingsOperationStore|pin|24379d84b7acb2e197ad8d0a2ebd5ce288bc67e8dfb4e1ca5a6b14eeb586ef0b=1
EloquentEvidenceRepository|descendantBatches|e1f0d1cfb9b027157e25d69c8d0d869604063a78a44095f418c030bd049ecd6d=2
ErrorTrackingController|timeseries|0bbc1d527caab15dc0b7a06c6f31fde1632004c2abdc90290a6ce7f8870c317c=1
EstimateGenerationPackagePersistenceService|appendItemRevision|d4ec1e76e79f6771d09253abe61a26d70d9cf8097938cb9a37958ad2e24e0b37=1
EstimateGenerationResourceIndexRuntime|dropAll|3b3c22cea9fc9b206c30aed61708660d18b9330864ffbf8adb2575c07ad68f27=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|a212c09c072096b7cb10a80ffe1f602f07e9e8f677d3f0cd0be4ea783b93b571=2
EstimateGenerationReviewQueueQuery|paginate|46db48d67821d685c79e72c2ccbddd741bc5973a8b68a7c0ad64cde15715ef00=1
EstimateGenerationReviewQueueQuery|paginate|d266eeac024d850cef7a10543766a63da974c2ebbd147404d3cf07f435801ec9=1
GeometryDependencyInvalidator|invalidate|bcc5a0377618acf1d537d87fbe6a2871231b31125d7b89c8d68a6f10315783e2=1
HoldingReportService|getContractsByContractor|c20e755f5120f417b06c6acc5849a3acaeead96e606b1d90100733c42de65b7f=1
ImmutableAuditPhaseBInvariantService|currentFingerprints|1c0c440f438e172217a5f315b384542453d27f77c2ae6c7587073edb59177067=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|72e3c5055d793c9ebd1ab5e09fed537677d061953d69ec77083596eace7f6f02=2
ImmutableAuditPhaseBInvariantService|functionCatalog|7267cbeddfd0d2780537086517e1f1aa67bb13074d661758d12cf316c118bdfb=1
ImmutableAuditPhaseBInvariantService|index|4bee9562103f6258f59dc19309a5fc3a46e7d55ba355e1e99ab5458bad836f2f=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|340a58f6d817f0eba037549686c75a9345ac3f20d41309d099226299a9436d2b=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|89d6abb6aed9a90bf7a322af527566d2b7278f4019ad9ef1949e27e333d485ab=1
ImmutableAuditPhaseBInvariantService|triggerCatalog|28360af450909a7d352631b28d0e216326772e24eb839885c633e54e911826c0=1
ImmutableAuditRolloutService|ensurePhaseBIndex|635216dc32df2cf1f34f39b218f66f7cea5e14faa3536002a5d40874e0353fc8=2
ImmutableAuditRolloutService|lockedRolloutMarker|0519ae89fcd554fa0588fcb60fff30d137a5ee7434b49d7eff1cca829b0e4f03=1
LaravelNotificationSnapshotDatabase|statement|983c34fc0d24d08ee5b6be7b5d92382dd37475bc4b86bb6d70e83805cf3982cf=1
NormativeRetrievalRolloutService|deploy|d71ad576355af7c4cafd4f0d6f3e84925e990993242cd01a22d7ec18d1a715d5=2
NotificationQueryService|unreadAggregatesForQuery|3b73a0be6b514303cd0a7ede528eee6dbe7fa1cfde0948732d33952f4f21f31a=3
PackageInputVersionBackfill|run|be1fe096251fcef0974749dc832cedbbfa6fe174f18304e5db768501d96fc25c=1
PaymentDocumentService|generateDocumentNumber|46180b89e4ef7b11a0aaf43a250931eb279be6947101d618451108d12d256088=1
PostgresNormativeCandidateSource|find|5bb7a9a8de0584cfa1508d2a804a338d043a72065bff0be409613cc6e8de4d36=1
RagIndexer|storeVector|efbfa5d8188c4934e4367d89377cfdea3b335ca2715efd0290ab937707094720=1
RagRetriever|postgresRows|ef1a21f074448f2daf4924dcdd2b0f4c9d9d1a93fa229e3d901a057109b982ad=1
ReportService|getContractPaymentsReport|448f5747fec9345fcfe5cc2d59c6f92badd88b1b8df84a7c7503d9320c6dec43=2
ReportService|getContractorSettlementsReport|bfdbd38a4a6d51fb7ec1e7b4b556383be3b08ac4c70540f545c9514f6c57c1d3=2
ReportService|getProjectProfitabilityReport|8473241a7ae0f9d5541fe41de8b6551c3aee1c840b5b89b98584adab92cfa8bb=2
ReportService|getProjectTimelinesReport|468f278764b9d7763b2c93981d57978106b5a5e5e2bec568c4a4ef59fd4603ad=1
ResetInvoiceNumberSequences|handle|d48ef6b61edc1478ed99e5bee4c799ec0ed850eee105c956e15599355ad69d86=1
ResetPaymentDocumentSequences|handle|8902815ebc6fbe4d82c75f7f0512c24b3f2e3fbdae3008cf330b31dbb2e08f21=1
SqlEstimateGenerationDashboardRepository|all|ab78e058936597281426a924be69bb12b6971721d26f26ac28aa07c2da01bc7a=1
SqlEstimateGenerationDashboardRepository|one|6a9abc92371e0fa2cff59c9cf449dedab815c8c8a74b3e4c999c42fb8177f793=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|b7ed78e8aa1beb18aa05762f20a1130c6e4d24820b7aaf54246be44137125dd4=6
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|1dc0b0c732dbf7e68fb95aa641e09363a766a55be840bac894663436c21ac6a6=4
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|e0a60aad568ea8220981b76908a5d6c957b070bf56e5e3664db6cce700ea382c=3
TrainingBenchmarkOnlineMigrationRuntime|validateConstraint|04db9dccd029035928403573f0ca2a803d0abb8e67d61ed12dec234859f98bec=1
MANIFEST)) as $line) {
            $separator = strrpos($line, '=');
            self::assertIsInt($separator);
            $structuralExemptions[substr($line, 0, $separator)] = (int) substr($line, $separator + 1);
        }
        $seenStructuralExemptions = array_fill_keys(array_keys($structuralExemptions), 0);
        $seenExemptions = array_fill_keys(array_keys($exemptions), 0);
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $scanner = new ContractMutationAstScanner;

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_contains($relative, '/migrations/')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (! is_string($source)) {
                continue;
            }
            foreach ($scanner->findings($source) as $finding) {
                if (isset($exemptions[$finding['fingerprint']])) {
                    $seenExemptions[$finding['fingerprint']]++;
                } elseif (preg_match('/\\|builder=([0-9a-f]{64})$/', $finding['fingerprint'], $match) === 1) {
                    $key = $finding['class'].'|'.$finding['method'].'|'.$match[1];
                    if (isset($structuralExemptions[$key])) {
                        $seenStructuralExemptions[$key]++;
                    } else {
                        $violations[] = "{$relative}:{$finding['line']}:{$finding['fingerprint']}";
                    }
                } else {
                    $violations[] = "{$relative}:{$finding['line']}:{$finding['fingerprint']}";
                }
            }
        }

        foreach ($exemptions as $fingerprint => $expectedCount) {
            if ($seenExemptions[$fingerprint] !== $expectedCount) {
                $violations[] = "exemption_count:{$fingerprint}:expected={$expectedCount}:actual={$seenExemptions[$fingerprint]}";
            }
        }
        foreach ($structuralExemptions as $key => $expectedCount) {
            if ($seenStructuralExemptions[$key] !== $expectedCount) {
                $violations[] = "structural_exemption_count:{$key}:expected={$expectedCount}:actual={$seenStructuralExemptions[$key]}";
            }
        }

        self::assertSame([], $violations, 'Unaudited Contract mutations: '.implode(', ', $violations));
    }

    public function test_only_test_environment_cleanup_has_a_narrow_bulk_delete_exception(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Console/Commands/SetupRBACTestEnvironment.php');
        self::assertIsString($source);
        self::assertStringContainsString("app()->environment(['local', 'testing'])", $source);
        self::assertStringContainsString("DB::table('contracts')", $source);
    }

    public function test_known_cross_module_contract_mutators_use_the_audited_boundary(): void
    {
        foreach ([
            'Services/Contract/ContractSideMutationService.php',
            'Services/Contract/ContractLifecycleService.php',
            'Services/Contract/ContractService.php',
            'Services/Contract/SupplementaryAgreementService.php',
            'BusinessModules/Features/Procurement/Services/PurchaseContractService.php',
            'BusinessModules/Features/BudgetEstimates/Services/Integration/EstimateCoverageService.php',
            'Services/CompletedWork/CompletedWorkService.php',
            'BusinessModules/Features/WorkflowManagement/Services/MobileWorkflowTaskService.php',
            'Services/Landing/MultiOrganizationService.php',
            'Services/Contract/ContractPaymentService.php',
            'Observers/ContractPerformanceActObserver.php',
            'Observers/SupplementaryAgreementObserver.php',
            'Console/Commands/Contracts/RecalculateNonFixedContractsTotalCommand.php',
            'Console/Commands/SyncContractsWithEventSourcing.php',
            'Console/Commands/MigrateLegacyContractsToEventSourcing.php',
            'Console/Commands/MigrateContractsToAgreements.php',
        ] as $relative) {
            $source = file_get_contents(dirname(__DIR__, 2).'/app/'.$relative);
            self::assertIsString($source);
            self::assertStringContainsString('ContractAuditedMutationService', $source, $relative);
        }
    }

    public function test_observer_idempotency_uses_change_fingerprint_and_persists_reconciliation_debt(): void
    {
        foreach (['ContractPerformanceActObserver.php', 'SupplementaryAgreementObserver.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__, 2).'/app/Observers/'.$file);
            self::assertIsString($source);
            self::assertStringContainsString('changeFingerprint(', $source);
            self::assertStringContainsString('recordDebt(', $source);
            self::assertStringNotContainsString("hash('sha256', (string) $", $source);
        }

        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_07_19_000321_create_contract_audit_reconciliation_debts.php');
        self::assertIsString($migration);
        self::assertStringContainsString("unique(['source_type', 'source_id', 'change_fingerprint'])", $migration);
        self::assertStringContainsString("timestampTz('resolved_at')", $migration);
    }

    public function test_ast_scanner_catches_alias_static_connection_raw_sql_and_mixed_files(): void
    {
        $scanner = new ContractMutationAstScanner;
        $source = <<<'PHP'
<?php
use App\Models\Contract;
use App\Models\Contract as ContractModel;
use Illuminate\Support\Facades\DB;
function mutate(): void {
    $targetContract = Contract::query()->findOrFail(1);
    $alias = $targetContract;
    $alias->save();
    Contract::whereKey(2)->update(['number' => 'static-root']);
    Contract::active()->where('id', 2)->increment('version');
    ContractModel::query()->whereKey(3)->decrement('version');
    Contract::query()->upsert([], ['id']);
    DB::connection('tenant')->table('contracts')->delete();
    DB::statement('UPDATE contracts SET number = 1');
    $sql = 'UPDATE contracts SET subject = 1';
    DB::statement($sql);
    $runtimeSql = $request->sql;
    DB::statement($runtimeSql);
    DB::unprepared("UPDATE contracts SET number = {$number}");
    DB::update(buildContractSql());
    DB::delete($enabled ? 'DELETE FROM contracts' : helperSql());
    $parts = ['sql' => 'INSERT INTO contracts DEFAULT VALUES'];
    DB::insert($parts['sql']);
    DB::raw($runtimeSql);
    $relationContract = $act->contract;
    $relationContract->touch();
    $loadedContract = $act->contract()->first();
    $loadedContract->restore();
    $repositoryContract = $this->contractRepository->findOrFail(3);
    $repositoryContract->forceDelete();
    $audit->recordCreated($targetContract);
    $targetContract->update(['number' => 'still-detected']);
    $closure = function (Contract $closureContract): void { $closureContract->delete(); };
    $arrow = fn (Contract $arrowContract) => $arrowContract->forceDelete();
}
class Contract { public function mutateSelf(): void { $this->saveQuietly(); } }
class PurchaseContract { public function harmless(): void { $this->save(); } }
class RepoService {
    public function __construct(private ContractRepositoryInterface $contracts) {}
    public function mutate(): void { $alias = $this->contracts; $alias->update(1, []); }
}
PHP;

        $findings = $scanner->findings($source);
        self::assertSame(['save', 'update', 'increment', 'decrement', 'upsert', 'delete', 'statement', 'statement', 'statement', 'unprepared', 'update', 'delete', 'insert', 'raw', 'touch', 'restore', 'forceDelete', 'update', 'delete', 'forceDelete', 'saveQuietly', 'update'], array_column($findings, 'operation'));
        self::assertNotContains('PurchaseContract', array_column($findings, 'class'));
    }

    public function test_semantic_exemption_count_rejects_duplicate_identical_repository_create(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
class Example {
    public function __construct(private ContractRepositoryInterface $contractRepository) {}
    public function create(): void {
        $this->contractRepository->create([]);
        $this->contractRepository->create([]);
    }
}
PHP);

        self::assertCount(2, array_filter($findings, static fn (array $finding): bool => $finding['fingerprint'] === 'Example|create|create|$this->contractRepository'));
    }

    public function test_ast_prefilter_resolves_facade_aliases_and_catches_writable_read_entry_points_in_all_scopes(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB as Database;
use Vendor\Telemetry\DB as TelemetryDb;

$topClosure = function () use ($runtimeSql): void {
    Database::selectOne($runtimeSql);
};
$topArrow = fn () => Database::scalar('SELECT mutate_contracts()');

function executeContractSql(string $runtimeSql): void
{
    Database::select('WITH changed AS (UPDATE "legal"."contracts" SET number = 1 RETURNING *) SELECT * FROM changed');
    \Illuminate\Support\Facades\DB::selectResultSets('DELETE FROM `tenant`.`contracts` RETURNING id');
    Database::connection('tenant')->cursor($runtimeSql);
    Database::selectFromWriteConnection($runtimeSql);
    Database::selectOne(contractSql());
    TelemetryDb::statement('UPDATE contracts SET number = 2');
}
PHP);

        self::assertSame(
            ['selectOne', 'scalar', 'select', 'selectResultSets', 'cursor', 'selectFromWriteConnection', 'selectOne'],
            array_column($findings, 'operation'),
        );
        self::assertNotContains('statement', array_column($findings, 'operation'));
        self::assertSame(
            ['global', 'global', 'global', 'global', 'global', 'global', 'global'],
            array_column($findings, 'class'),
        );
        self::assertSame(
            ['closure@5', 'arrow@8', 'executeContractSql', 'executeContractSql', 'executeContractSql', 'executeContractSql', 'executeContractSql'],
            array_column($findings, 'method'),
        );
    }

    public function test_dynamic_sql_exemptions_have_runtime_guards_before_database_execution(): void
    {
        $runtime = new \App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
        try {
            $runtime->ensureConstraint('contracts', 'forbidden_probe', 'CHECK (id > 0)');
            self::fail('Online migration runtime must reject the contracts table.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('estimate_generation_online_migration_contract_table_forbidden', $error->getMessage());
        }

        $database = new \App\BusinessModules\Features\Notifications\Services\LaravelNotificationSnapshotDatabase;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('notification_snapshot_statement_forbidden');
        $database->statement('UPDATE contracts SET number = number');
    }

    public function test_ast_tracks_injected_connections_pdo_prepared_statements_and_qualified_contract_tables(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
use Illuminate\Database\ConnectionInterface;
final class InjectedDatabase {
    public function __construct(private ConnectionInterface $database, private \PDO $pdo) {}
    public function mutate(ConnectionInterface $connection): void {
        $connection->statement('UPDATE public.contracts SET number = 1');
        $this->database->table('"public"."contracts"')->delete();
        $this->database->getPdo()->exec('DELETE FROM `public`.`contracts`');
        $rawPdo = $connection->getRawPdo();
        $statement = $rawPdo->prepare('INSERT INTO public.contracts DEFAULT VALUES');
        $statement->execute();
        $this->pdo->query('SELECT apply_legal_change()');
    }
}
PHP);

        self::assertSame(['statement', 'delete', 'exec', 'execute', 'query'], array_column($findings, 'operation'));
    }

    public function test_dynamic_sql_exemption_hash_changes_with_builder_and_one_hop_dependency_body(): void
    {
        $scanner = new ContractMutationAstScanner;
        $first = $scanner->findings(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
final class HelperSql {
    private function fragment(): string { return 'SELECT 1'; }
    private function build(): string { return $this->fragment(); }
    public function run(): void { DB::selectOne($this->build()); }
}
PHP);
        $second = $scanner->findings(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
final class HelperSql {
    private function fragment(): string { return 'SELECT apply_legal_change()'; }
    private function build(): string { return $this->fragment(); }
    public function run(): void { DB::selectOne($this->build()); }
}
PHP);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertSame($first[0]['receiver'], $second[0]['receiver']);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
        self::assertStringContainsString('|builder=', $first[0]['fingerprint']);
    }
}
