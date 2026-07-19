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
        $rawSqlExemptions = array_filter(explode("\n", <<<'FINGERPRINTS'
EstimateGenerationResourceIndexRuntime|dropAll|statement|\Illuminate\Support\Facades\DB::statement($index['dropIfExists'])
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement($index['drop'])
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement($index['create'])
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement('DROPINDEXCONCURRENTLYIFEXISTS'.$expectedSchema.'.'.$probe)
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement((string)$probeSql)
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement('DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$probe)
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement('DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$name)
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|statement|\Illuminate\Support\Facades\DB::statement($createSql)
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$qualified}ADDCONSTRAINT{$name}{$definition}NOTVALID")
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$qualified}DROPCONSTRAINTIFEXISTS{$probe}")
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$qualified}ADDCONSTRAINT{$probe}{$definition}NOTVALID")
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$qualified}DROPCONSTRAINT{$probe}")
TrainingBenchmarkOnlineMigrationRuntime|validateConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$schema}.{$table}VALIDATECONSTRAINT{$name}")
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|statement|\Illuminate\Support\Facades\DB::statement("LOCKTABLE{$schema}.{$table}INACCESSEXCLUSIVEMODE")
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$schema}.{$table}DROPCONSTRAINTIFEXISTS{$finalName}")
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|statement|\Illuminate\Support\Facades\DB::statement("ALTERTABLE{$schema}.{$table}RENAMECONSTRAINT{$temporaryName}TO{$finalName}")
HoldingReportService|getContractsByContractor|raw|\Illuminate\Support\Facades\DB::raw("({$query->toSql()})assub")
ResetInvoiceNumberSequences|handle|statement|\Illuminate\Support\Facades\DB::statement("DROPSEQUENCEIFEXISTS".$seq->relname)
ResetPaymentDocumentSequences|handle|statement|\Illuminate\Support\Facades\DB::statement("DROPSEQUENCEIFEXISTS".$seq->relname)
RagIndexer|storeVector|update|\Illuminate\Support\Facades\DB::update($sql,[$vector,$chunk->id])
LaravelNotificationSnapshotDatabase|statement|statement|\Illuminate\Support\Facades\DB::statement($sql)
NotificationQueryService|unreadAggregatesForQuery|raw|\Illuminate\Support\Facades\DB::raw($categoryExpression)
NotificationQueryService|unreadAggregatesForQuery|raw|\Illuminate\Support\Facades\DB::raw($typeExpression)
NotificationQueryService|unreadAggregatesForQuery|raw|\Illuminate\Support\Facades\DB::raw($notificationTypeExpression)
ErrorTrackingController|timeseries|raw|\Illuminate\Support\Facades\DB::raw("DATE_TRUNC('{$interval}',last_seen_at)astime")
DashboardController|contractsRequiringAttention|raw|\Illuminate\Support\Facades\DB::raw('CASEWHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=100THEN3WHENend_date<CURRENT_TIMESTAMPANDstatus=\''.\App\Enums\Contract\ContractStatusEnum::ACTIVE->value.'\'THEN2WHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=90THEN1ELSE0END')
ContractService|getContractsSummary|raw|\Illuminate\Support\Facades\DB::raw("({$nearingLimitSubquery->toSql()})assubquery")
ReportService|getContractPaymentsReport|raw|\Illuminate\Support\Facades\DB::raw('(SELECTCOALESCE(SUM(paid_amount),0)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)aspaid_amount')
ReportService|getContractPaymentsReport|raw|\Illuminate\Support\Facades\DB::raw('(SELECTCOALESCE(SUM(amount),0)FROMcontract_performance_actsWHEREcontract_id=contracts.idANDproject_id='.$projectId.'ANDis_approved=true)ascompleted_amount')
ReportService|getContractorSettlementsReport|raw|\Illuminate\Support\Facades\DB::raw($completedAmountSubquery)
ReportService|getContractorSettlementsReport|raw|\Illuminate\Support\Facades\DB::raw('COALESCE(SUM((SELECTSUM(paid_amount)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)),0)astotal_paid')
ReportService|getProjectProfitabilityReport|raw|\Illuminate\Support\Facades\DB::raw('(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')ascontractor_costs')
ReportService|getProjectProfitabilityReport|raw|\Illuminate\Support\Facades\DB::raw('(SELECTCOALESCE(SUM(quantity*price),0)FROMwarehouse_movementsWHEREproject_id=projects.idANDwarehouse_movements.organization_id='.$organizationId.'ANDmovement_type=\'receipt\')asmaterial_costs')
ReportService|getProjectTimelinesReport|raw|\Illuminate\Support\Facades\DB::raw('(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')astotal_contract_amount')
FINGERPRINTS));
        $exemptions += array_fill_keys($rawSqlExemptions, 1);
        $rawSqlEvidence = [];
        foreach ($rawSqlExemptions as $fingerprint) {
            $rawSqlEvidence[$fingerprint] = match (true) {
                str_starts_with($fingerprint, 'EstimateGenerationResourceIndexRuntime|') => 'SQL берётся только из закрытого INDEXES allowlist для estimate_generation_* индексов.',
                str_starts_with($fingerprint, 'TrainingBenchmarkOnlineMigrationRuntime|') => 'assertNotContractTable структурно запрещает contracts до выполнения любого dynamic DDL.',
                str_starts_with($fingerprint, 'ResetInvoiceNumberSequences|') => 'Имена поступают из pg_class с relkind=S и жёстким invoice_seq_% prefix.',
                str_starts_with($fingerprint, 'ResetPaymentDocumentSequences|') => 'Имена поступают из pg_class с relkind=S и жёстким payment_doc_seq_% prefix.',
                str_starts_with($fingerprint, 'RagIndexer|') => 'Обе ветки локальной константы SQL обновляют только ai_rag_chunks.embedding по id.',
                str_starts_with($fingerprint, 'LaravelNotificationSnapshotDatabase|') => 'Метод принимает только точную константу SET TRANSACTION ISOLATION LEVEL REPEATABLE READ и иначе fail-closed.',
                str_contains($fingerprint, '|raw|') => 'DB::raw формирует выражение read-only SELECT/query-builder и сам не исполняет mutation statement.',
            };
        }
        self::assertSame(count($rawSqlExemptions), count($rawSqlEvidence));
        self::assertNotContains('', $rawSqlEvidence);
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
}
