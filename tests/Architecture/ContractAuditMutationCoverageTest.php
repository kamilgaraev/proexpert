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
AiBudgetGuard|claimWire|82bff5abc33bf29e3f4725595126d1807bd4a5f96df2d989e82c496649f394a8|evidence=selectOne:sql=SELECT eg_claim_ai_budget_wire(?) AS claimed=1
AiBudgetGuard|pendingReconciliation|068a2d5453e6f3ea4c3ae13a36d7a68a5e7eb06cb9d34b1155e5dfb3e8db5a2c|evidence=selectOne:sql=SELECT eg_mark_ai_budget_reconciliation(?) AS pending=1
AiBudgetGuard|reconcileExpired|109f77778d94c3dc64d8626e1ed1f048fa2269368adaeaa7ad6c2c65d15749b0|evidence=selectOne:sql=SELECT eg_reconcile_expired_ai_budgets(?) AS reconciled=1
AiBudgetGuard|releaseBeforeWire|5244afc1591725d4d0243cb35273e97ee8b71d0da465835ac15a37f355834267|evidence=selectOne:sql=SELECT eg_release_ai_budget(?) AS released=1
AiBudgetGuard|reserve|4144d4f2aa8c2064e9add021d6f28892f2259ea555755e73bed0712891674c48|evidence=selectOne:sql=SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) AS reservation_id=1
AiBudgetGuard|settle|6b5d627d2409ea12a81956f3ef4bab0ba7c2cb30427f4cf972443d2300498d2e|evidence=selectOne:sql=SELECT eg_settle_ai_budget(?, ?, ?) AS settled=1
BenchmarkRunRepository|lockStorageObject|f508c8c20db3cad704865d21edb825273d8a68994ef176f45af56917395e5b7c|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=1
BenchmarkRunRepository|start|915dd588bfeb1f8d2fda948eccba2aa29d1803a90541bb88beffa3c4ae948c5b|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))=1
BenchmarkRunRepository|unlockStorageObject|6d25371effcc2108e65acf661124937347c8de52414910dc9980410cbac0f058|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=1
BuildSessionOperationalSnapshot|finalization|af8e53fd8876260a78ba1106f214aca5ba47081b85f170657073ba5781ea3321|evidence=selectOne:sql=SELECT (SELECT COUNT(*) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_total, (SELECT COALESCE(MAX(id), 0) …=1
BuildSessionOperationalSnapshot|sourceWatermarks|0365acfd0884897c6a891ea3d51a3d4926e6da361ce0f6cc80e8bb164a26be59|evidence=selectOne:sql=SELECT (SELECT COUNT(*) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_count, (SELECT COALESCE(MAX(id), 0) FROM e…=1
ContractService|getContractsSummary|2a8bb800815c68ad80b34e7d3982329c4b254a2cea112c14da4be2360c439391|evidence=raw:argument="({$nearingLimitSubquery->toSql()})assubquery"=1
DashboardController|contractsRequiringAttention|30a3d48cfc034d53b509569746a2d398741212df5bcb72430d4be9fb67f01d92|evidence=raw:argument='CASEWHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=100THEN3WHENend_date<CURRENT_TIMESTAMPANDstatus=\''.\App\Enums\Contract\ContractStatusEnum::ACTIVE->value.'\'THEN2WHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=90THEN1ELSE0END'=1
DashboardController|getTopCreditors|f53a237e39c0da48feb1a3567105b3da16e47b06564472b9c9e465b60ee038e4|evidence=raw:sql=CASE WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id) ELSE (SELECT name FROM organizations WHERE id = payment_documents…=1
DashboardController|getTopDebtors|37abb6d5a1f070d894758f639f7a80d5a5a770cb540542fcffe819fd380945e0|evidence=raw:sql=CASE WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id) ELSE (SELECT name FROM organizations WHERE id = payment_documents…=1
DatabaseNotificationCommitSequencer|run|ae6d65e7611fa8637ca461d2d2f06498f2adf16314356e880ab1fc8d6b0417a4|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))=1
EloquentBuildingModelStore|transaction|bb7ee03d414b559819357efb84149ef05374b9ead4835e3a074bb150d289fa96|evidence=select:sql=SELECT pg_advisory_xact_lock(?, ?)=1
EloquentEffectiveSettingsOperationStore|pin|7717853c9e554c35367d441144b6bb69137d3cc41af32448e405255663af39e4|evidence=selectOne:sql=SELECT * FROM eg_pin_ai_operation_settings(?, ?, ?)=1
EloquentEvidenceRepository|descendantBatches|fbb69f230e8425d7a2200ca7a9e1058c347865fbbbabc5a2cacae3a4ccd22feb|evidence=insert:argument=$sql=1
EloquentEvidenceRepository|descendantBatches|fbb69f230e8425d7a2200ca7a9e1058c347865fbbbabc5a2cacae3a4ccd22feb|evidence=statement:argument="CREATETEMPTABLEIFNOTEXISTS{$temporaryTable}(idbigintPRIMARYKEY)ONCOMMITDROP"=1
EloquentEvidenceRepository|transaction|d766c1da7b85d8d22bb1dd0ed6d3a5e2d54987d6ba91b5bd189eab50e92be2ab|evidence=select:sql=SELECT pg_advisory_xact_lock(?, ?)=1
ErrorTrackingController|timeseries|dbd3a697957a4541e394215753c3c17c6e7b1f90ef9cf3e8d24408bfdf4c3baf|evidence=raw:argument="DATE_TRUNC('{$interval}',last_seen_at)astime"=1
EstimateGenerationPackagePersistenceService|appendItemRevision|874d088ac7ec02473740d5a3918138afc5a3a6d173c894e62369880676fcf97b|evidence=select:sql=SELECT public.eg_finalize_package_item_price(?)=1
EstimateGenerationResourceIndexRuntime|dropAll|bd6cd6d8f7708bf6bda072a48af563a6ec582fb5b518ae4e3a51a976303e6f11|evidence=statement:argument=$index['dropIfExists']=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|71dafb0555e895cb83f380921568627e2b4b9e2c58a9d16507d3c57d5b30c5c3|evidence=statement:argument=$index['create']=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|71dafb0555e895cb83f380921568627e2b4b9e2c58a9d16507d3c57d5b30c5c3|evidence=statement:argument=$index['drop']=1
EstimateGenerationResourceIndexRuntime|findIndex|4e060f76db194fe35dfdf2bf4fe2cdb4625894edf60441721f80f6e736cda0eb|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, pg_get_indexdef(c.oid) AS definition FROM pg_class AS c INNER JOIN pg_namespace AS n ON n.oid = c.relnamespace INNER JOIN pg_index AS i ON i.inde…=1
EstimateGenerationReviewQueueQuery|paginate|57b228b9ba3240e69f32df0003ac4592f9d0c43f08a58631c9b96cffc2874e56|evidence=select:argument=$this->pageSql($where)=1
EstimateGenerationReviewQueueQuery|paginate|70e599835af2edfbd5ee9985fd6b931030ff1c707a60c0fdf2d42f8f0d2640a1|evidence=selectOne:argument=$this->summarySql($where)=1
EstimateGenerationTrainingDatasetService|appendVersion|e34c72d10591b580f67ff955b8403d70da97a0d378196926f3dcd094d5aecf1a|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))=1
GeometryDependencyInvalidator|invalidate|36d63fc1179952e4f307d85199ba1908e39cb2a4b7754a837583d908421de037|evidence=select:argument='WITHRECURSIVEdescendants(id)AS(SELECTchild_idFROMestimate_generation_evidence_edgesWHEREsession_id=?ANDparent_idIN('.implode(',',array_fill(0,$roots->count(),'?')).')UNIONSELECTedge.child_idFROMestimate_generation_evidence_edgesedgeJOINdescendantstreeONtree.id=edge.parent_idWHEREedge.session_id=?)SELECTidFROMdescendants'=1
HoldingReportService|getContractsByContractor|bd4040e774ceec718179b0ec33ef20027984e5fda1b49884724025c2979bf962|evidence=raw:argument="({$query->toSql()})assub"=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|9d835f4f075e51c1741894af13a1207e89b20510ee3f415a14ff1a90b4057411|evidence=statement:argument="CREATEUNIQUEINDEXCONCURRENTLY{$name}ONimmutable_audit_events(".implode(',',$columns).")WHERE{$predicate}"=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|9d835f4f075e51c1741894af13a1207e89b20510ee3f415a14ff1a90b4057411|evidence=statement:argument="DROPINDEXCONCURRENTLYIFEXISTS{$name}"=1
ImmutableAuditPhaseBInvariantService|functionCatalog|0d8aa3718ad0e50d879480a0bb32762d33195cb857e181b30cecb7633b10e7a9|evidence=selectOne:sql=SELECT p.prosrc, pg_get_function_identity_arguments(p.oid) AS identity_arguments, pg_get_function_result(p.oid) AS result, l.lanname AS language, p.provolatile AS volatility, p.pro…=1
ImmutableAuditPhaseBInvariantService|index|b30477b8473142e134ebd84946d4e3f700803c603a2de3485ebc2b487537dee6|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, i.indisunique, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND …=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|38759a04e585ccaeb2236dbd9d3af5b8b3fff59b6af1c14ac9337dfe8d298e08|evidence=statement:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::SEQUENCE_ALTER_SQL=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|38759a04e585ccaeb2236dbd9d3af5b8b3fff59b6af1c14ac9337dfe8d298e08|evidence=statement:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::SEQUENCE_CREATE_SQL=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|9f1b204343253bd4bd94a087f0be9b2f209777129283653698d1a663483d9f45|evidence=unprepared:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::canonicalCoreSql()=1
ImmutableAuditPhaseBInvariantService|sequenceCatalog|c59363b4a723c8f5e51c78a3274ddc9da0054da7823c71e698f06da13cfaf5bb|evidence=selectOne:sql=SELECT s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by, s.cycle, s.cache_size, CASE WHEN pg_get_userbyid(q.relowner) = current_user THEN '$database_owner' ELSE…=1
ImmutableAuditPhaseBInvariantService|triggerCatalog|173378f3f50fce019f82fd984c4d59491eab2a477df90e9d7addd761d90e5f20|evidence=selectOne:sql=SELECT t.tgname AS name, t.tgenabled AS enabled, t.tgisinternal AS internal, c.relname AS relation, p.proname AS function_name, t.tgtype AS type, CASE WHEN function_namespace.nspna…=1
ImmutableAuditRecorder|activateWriterCredential|b2b04af3e4607eb7f6c2e49bea7b80e4fb82d908b5d5a192a0560afbc94851e4|evidence=execute:argument=[$credential]=1
ImmutableAuditRolloutService|cutover|f51d19ebb53d20561b231925732298aec6cb54928ea4b3b61e562357ec748c93|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|cutover|f51d19ebb53d20561b231925732298aec6cb54928ea4b3b61e562357ec748c93|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|cutover|f51d19ebb53d20561b231925732298aec6cb54928ea4b3b61e562357ec748c93|evidence=statement:sql=SELECT setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1)), EXISTS (S…=1
ImmutableAuditRolloutService|ensurePhaseBIndex|9a877e6b35f29b1fd69418e044283bb8f10e8a60bcfcdd55ddafe1386ca99a44|evidence=statement:argument="CREATEUNIQUEINDEXCONCURRENTLY{$name}ONimmutable_audit_events({$columnSql})WHERE{$predicate}"=1
ImmutableAuditRolloutService|ensurePhaseBIndex|9a877e6b35f29b1fd69418e044283bb8f10e8a60bcfcdd55ddafe1386ca99a44|evidence=statement:argument="DROPINDEXCONCURRENTLYIFEXISTS{$name}"=1
ImmutableAuditRolloutService|lockedRolloutMarker|9c408bfffb3db076f3597207da2311613b6a5ae9cb57aa0fdbf5ee7968ac31fc|evidence=selectOne:argument=<<<SQLSELECTphase,writer_version,writer_credential_hash,drain_marker,drain_confirmed_at,drain_confirmed_atISNOTNULLANDdrain_confirmed_at>=clock_timestamp()-make_interval(mins=>CAST(?ASinteger))ASdrain_freshFROMimmutable_audit_rolloutWHEREsingleton=true{$forUpdateSql}SQL=1
ImmutableAuditRolloutService|repairPermanentInvariants|c9560da519d136c4e44f5620a5aa43e07b22423141597ca620efa48cd1903a95|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|repairPermanentInvariants|c9560da519d136c4e44f5620a5aa43e07b22423141597ca620efa48cd1903a95|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=2
LaravelNotificationSnapshotDatabase|statement|57d3dd080161ab85450b391a003d4ff5f3c9145c6bcbccdf80a432c9558af5eb|evidence=statement:argument=$sql=1
NormativeRetrievalRolloutService|deploy|e106989699ed295a6926b833a0c9f78a642ad15347be4d2b09e0eb8b07201c61|evidence=select:sql=SELECT pg_advisory_unlock(hashtext('normative-retrieval-v1'))=1
NormativeRetrievalRolloutService|deploy|e106989699ed295a6926b833a0c9f78a642ad15347be4d2b09e0eb8b07201c61|evidence=selectOne:sql=SELECT pg_try_advisory_lock(hashtext('normative-retrieval-v1')) AS locked=1
NormativeRetrievalRolloutService|deploy|e106989699ed295a6926b833a0c9f78a642ad15347be4d2b09e0eb8b07201c61|evidence=unprepared:sql=DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norm_semantic_score_ck') THEN ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_…=1
NormativeRetrievalRolloutService|deploy|e106989699ed295a6926b833a0c9f78a642ad15347be4d2b09e0eb8b07201c61|evidence=unprepared:sql=DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norms_validity_ck') THEN ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (…=1
NotificationQueryService|unreadAggregatesForQuery|ef75407bd8494b456d277b2c25147c9e346a6c754690bd55a074f06b5dda4246|evidence=raw:argument=$categoryExpression=1
NotificationQueryService|unreadAggregatesForQuery|ef75407bd8494b456d277b2c25147c9e346a6c754690bd55a074f06b5dda4246|evidence=raw:argument=$notificationTypeExpression=1
NotificationQueryService|unreadAggregatesForQuery|ef75407bd8494b456d277b2c25147c9e346a6c754690bd55a074f06b5dda4246|evidence=raw:argument=$typeExpression=1
PackageInputVersionBackfill|run|83a83f0fcec0492aca89c48e90f964cb2656803ff5216fe5df3d341c9db9fab2|evidence=affectingStatement:argument=self::SQL=1
PaymentDocumentService|generateDocumentNumber|cfa2d24ea9d2f89f29539b3ace87ccc2d68821e6d9ed1846608701a57aef1548|evidence=selectOne:sql=SELECT get_next_payment_document_number(?, ?, ?, ?) as number=1
PostgresNormativeCandidateSource|find|b22c58247dd2ac060813ea67dbd1d26f98dd977e750fc4f482950afd5a06d340|evidence=select:argument=self::QUERY_CONTRACT=1
RagIndexer|storeVector|0fc24d420f10f1f5da7762ba76a709dfd06ae1bae894f5e089b4b4bb86d46cc9|evidence=update:argument=$sql=1
RagRetriever|postgresRows|1497144fd73f582a159515a20e7f3e1dd528cfeb115d02930603014e86d8f915|evidence=select:argument=$sql=1
ReportService|getContractPaymentsReport|b97e605e6da5a95398cb3fd4d734cc1018f35fd367648de5b5603d614884916b|evidence=raw:argument='(SELECTCOALESCE(SUM(amount),0)FROMcontract_performance_actsWHEREcontract_id=contracts.idANDproject_id='.$projectId.'ANDis_approved=true)ascompleted_amount'=1
ReportService|getContractPaymentsReport|b97e605e6da5a95398cb3fd4d734cc1018f35fd367648de5b5603d614884916b|evidence=raw:argument='(SELECTCOALESCE(SUM(paid_amount),0)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)aspaid_amount'=1
ReportService|getContractorSettlementsReport|8efc4214c760bd7196987f00d7f02e4715ac969036e5b3d92a68a3d794f8cc1c|evidence=raw:argument=$completedAmountSubquery=1
ReportService|getContractorSettlementsReport|8efc4214c760bd7196987f00d7f02e4715ac969036e5b3d92a68a3d794f8cc1c|evidence=raw:argument='COALESCE(SUM((SELECTSUM(paid_amount)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)),0)astotal_paid'=1
ReportService|getProjectProfitabilityReport|2493f000e6f1cbf7a3be6268f98c6922b3a9c5d5b07895216da861a35ab36950|evidence=raw:argument='(SELECTCOALESCE(SUM(quantity*price),0)FROMwarehouse_movementsWHEREproject_id=projects.idANDwarehouse_movements.organization_id='.$organizationId.'ANDmovement_type=\'receipt\')asmaterial_costs'=1
ReportService|getProjectProfitabilityReport|2493f000e6f1cbf7a3be6268f98c6922b3a9c5d5b07895216da861a35ab36950|evidence=raw:argument='(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')ascontractor_costs'=1
ReportService|getProjectTimelinesReport|130c00efe407cb2fbc04f10a8cb9132bffc4ad115e66400a8c0aae9cfa764e1a|evidence=raw:argument='(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')astotal_contract_amount'=1
ResetInvoiceNumberSequences|handle|ff9d57b6525fbfc8700d4fdcfe7d31da387b30bc5815bb431112c4d4a07bb6c3|evidence=statement:argument="DROPSEQUENCEIFEXISTS".$seq->relname=1
ResetPaymentDocumentSequences|handle|56267ac3cb08778355f83d0fc4e3d672bf902512a7324da4bf43d16606036b5e|evidence=statement:argument="DROPSEQUENCEIFEXISTS".$seq->relname=1
SearchService|searchNearby|52055bff3b28c9bb787f1e6fed1ed72975ac50d52cd83debf24300dd47bd636a|evidence=select:sql=SELECT id, name, address, latitude, longitude, status, budget_amount, (6371 * acos( cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)…=1
SqlEstimateGenerationDashboardRepository|all|171c05b6476ea126861c348f8d0f8ce10beff37221a50a34d1c1454ee5c36fdd|evidence=select:argument=$query->sql=1
SqlEstimateGenerationDashboardRepository|one|26c10cc7036f46bb543fe28be6ad0bdafe479e32799aeaa140f3ff4ba20a270a|evidence=selectOne:argument=$query->sql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=select:sql=SELECT c.relname, pg_get_indexdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN (?, ?)=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, i.indisunique, ns.nspname AS schema_name, tbl.relname AS table_name, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum,…=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=statement:argument=$createSql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=statement:argument='DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$name=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=statement:argument='DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$probe=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=statement:argument='DROPINDEXCONCURRENTLYIFEXISTS'.$expectedSchema.'.'.$probe=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|0c207bd57371b9fc298e1d203e626173fdb2563c022dbac7bab28d87497c515e|evidence=statement:argument=(string)$probeSql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|897c3cf4e604fd80bceaf3f25b305a3ca4c3c74e77aae7fe0107d54a5fe4541d|evidence=selectOne:sql=SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? …=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|897c3cf4e604fd80bceaf3f25b305a3ca4c3c74e77aae7fe0107d54a5fe4541d|evidence=selectOne:sql=SELECT pg_get_constraintdef(c.oid, true) AS definition, c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHER…=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|897c3cf4e604fd80bceaf3f25b305a3ca4c3c74e77aae7fe0107d54a5fe4541d|evidence=statement:argument="ALTERTABLE{$qualified}ADDCONSTRAINT{$name}{$definition}NOTVALID"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|897c3cf4e604fd80bceaf3f25b305a3ca4c3c74e77aae7fe0107d54a5fe4541d|evidence=statement:argument="ALTERTABLE{$qualified}ADDCONSTRAINT{$probe}{$definition}NOTVALID"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|897c3cf4e604fd80bceaf3f25b305a3ca4c3c74e77aae7fe0107d54a5fe4541d|evidence=statement:argument="ALTERTABLE{$qualified}DROPCONSTRAINTIFEXISTS{$probe}"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|897c3cf4e604fd80bceaf3f25b305a3ca4c3c74e77aae7fe0107d54a5fe4541d|evidence=statement:argument="ALTERTABLE{$qualified}DROPCONSTRAINT{$probe}"=1
TrainingBenchmarkOnlineMigrationRuntime|restoreSessionTimeouts|67f339b40b3dca05a2106751c61f7ca0632cefc65fdf77e25d197a70281984a8|evidence=select:sql=SELECT set_config('lock_timeout', ?, false), set_config('statement_timeout', ?, false)=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|53f71296deb1f9789d7b7cab4f96895e93042319337b619824d7d3877c1d0e9f|evidence=statement:argument="ALTERTABLE{$schema}.{$table}DROPCONSTRAINTIFEXISTS{$finalName}"=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|53f71296deb1f9789d7b7cab4f96895e93042319337b619824d7d3877c1d0e9f|evidence=statement:argument="ALTERTABLE{$schema}.{$table}RENAMECONSTRAINT{$temporaryName}TO{$finalName}"=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|53f71296deb1f9789d7b7cab4f96895e93042319337b619824d7d3877c1d0e9f|evidence=statement:argument="LOCKTABLE{$schema}.{$table}INACCESSEXCLUSIVEMODE"=1
TrainingBenchmarkOnlineMigrationRuntime|validateConstraint|23e4e90fbfc41585687f7934395d1198fbbb0c5c9e72ecdec92855077f4651a8|evidence=statement:argument="ALTERTABLE{$schema}.{$table}VALIDATECONSTRAINT{$name}"=1
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
        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_contains(strtolower($relative), '/migrations/')) {
                continue;
            }
            $files[] = $file->getPathname();
        }
        foreach ($scanner->findingsInFiles($files) as $finding) {
            $relative = str_replace('\\', '/', substr($finding['file'], strlen($root) + 1));
            if (isset($exemptions[$finding['fingerprint']])) {
                $seenExemptions[$finding['fingerprint']]++;
            } elseif (preg_match('/\\|builder=([0-9a-f]{64})$/', $finding['fingerprint'], $match) === 1) {
                $key = $finding['class'].'|'.$finding['method'].'|'.$match[1].'|evidence='.$finding['evidence'];
                if (isset($structuralExemptions[$key])) {
                    $seenStructuralExemptions[$key]++;
                } else {
                    $violations[] = "{$relative}:{$finding['line']}:{$finding['fingerprint']}";
                }
            } else {
                $violations[] = "{$relative}:{$finding['line']}:{$finding['fingerprint']}";
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
        self::assertStringNotContainsString('|builder=unresolved-', $findings[3]['fingerprint']);
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

    public function test_ast_tracks_pdo_statement_types_aliases_arrays_returns_and_nested_captures(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
use Illuminate\Database\ConnectionInterface;
final class StatementFlow {
    public function __construct(private ConnectionInterface $connection, private \PDOStatement $property) {}
    private function prepared(): \PDOStatement { return $this->connection->getPdo()->prepare('UPDATE contracts SET number = 1'); }
    public function run(\PDOStatement $parameter): void {
        $returned = $this->prepared();
        $alias = $returned;
        $captured = $this->connection->getPdo()->prepare('TRUNCATE contracts');
        $statements['contract'] = $this->connection->getRawPdo()->prepare('DELETE FROM contracts');
        $closure = function () use ($parameter): void { $parameter->execute(); };
        $capturedClosure = function () use ($captured): void { $captured->execute(); };
        $arrow = fn () => $this->property->execute();
        $alias->execute();
        $statements['contract']->execute();
    }
}
PHP);

        self::assertSame(['execute', 'execute', 'execute', 'execute', 'execute'], array_column($findings, 'operation'));
    }

    public function test_raw_sql_custom_and_quoted_functions_fail_closed_without_pg_prefix_escape(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
function run(): void {
    DB::select('SELECT count(*), lower(name) FROM contracts');
    DB::select('SELECT pg_custom_mutator()');
    DB::select('SELECT "legal"."apply_change"()');
}
PHP);

        self::assertSame(['select', 'select'], array_column($findings, 'operation'));
    }

    public function test_structural_hash_is_transitive_and_cycle_safe(): void
    {
        $scanner = new ContractMutationAstScanner;
        $first = $scanner->findings(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
final class DeepSql {
    private function leaf(): string { return 'SELECT 1'; }
    private function middle(): string { return $this->leaf(); }
    private function cycleA(): string { return false ? $this->cycleB() : $this->middle(); }
    private function cycleB(): string { return $this->cycleA(); }
    public function run(): void { DB::selectOne($this->cycleA()); }
}
PHP);
        $second = $scanner->findings(<<<'PHP'
<?php
use Illuminate\Support\Facades\DB;
final class DeepSql {
    private function leaf(): string { return 'SELECT apply_legal_change()'; }
    private function middle(): string { return $this->leaf(); }
    private function cycleA(): string { return false ? $this->cycleB() : $this->middle(); }
    private function cycleB(): string { return $this->cycleA(); }
    public function run(): void { DB::selectOne($this->cycleA()); }
}
PHP);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
    }

    public function test_project_index_invalidates_external_static_helper_and_hashes_every_cycle_entry_symmetrically(): void
    {
        $directory = sys_get_temp_dir().'/most-contract-ast-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $caller = $directory.'/Caller.php';
        $helper = $directory.'/SqlFactory.php';
        file_put_contents($caller, <<<'PHP'
<?php
namespace ProjectIndex;
use Illuminate\Support\Facades\DB;
final class Caller {
    public function first(): void { DB::selectOne(SqlFactory::a()); }
    public function second(): void { DB::selectOne(SqlFactory::b()); }
}
PHP);
        $firstHelper = <<<'PHP'
<?php
namespace ProjectIndex;
final class SqlFactory {
    public static function a(): string { return self::b(); }
    public static function b(): string { return false ? self::a() : 'SELECT 1'; }
}
PHP;
        $secondHelper = str_replace("'SELECT 1'", "'SELECT apply_legal_change()'", $firstHelper);
        file_put_contents($helper, $firstHelper);
        $scanner = new ContractMutationAstScanner;
        $first = $scanner->findingsInFiles([$caller, $helper]);
        file_put_contents($helper, $secondHelper);
        $second = $scanner->findingsInFiles([$caller, $helper]);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame(substr($first[0]['fingerprint'], -64), substr($first[1]['fingerprint'], -64));
        self::assertSame(substr($second[0]['fingerprint'], -64), substr($second[1]['fingerprint'], -64));
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
        self::assertNotSame($first[1]['fingerprint'], $second[1]['fingerprint']);
        unlink($caller);
        unlink($helper);
        rmdir($directory);
    }

    public function test_project_index_fails_closed_for_an_unknown_sql_builder_and_reuses_only_an_exact_content_snapshot(): void
    {
        $directory = sys_get_temp_dir().'/most-contract-ast-cache-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $caller = $directory.'/Caller.php';
        file_put_contents($caller, <<<'PHP'
<?php
namespace ProjectIndexCache;
use Illuminate\Support\Facades\DB;
final class Caller {
    public function run(): void { DB::selectOne(UnknownSqlFactory::sql()); }
    public function dynamic(object $factory): void { DB::selectOne($factory->sql()); }
}
PHP);
        $scanner = new ContractMutationAstScanner;
        $first = $scanner->findingsInFiles([$caller]);
        $second = $scanner->findingsInFiles([$caller]);

        self::assertCount(2, $first);
        self::assertSame($first, $second);
        foreach ($first as $finding) {
            self::assertStringContainsString('|builder=unresolved-', $finding['fingerprint']);
        }
        self::assertSame(['hits' => 1, 'misses' => 1], $scanner->projectCacheMetrics());

        file_put_contents($caller, str_replace('UnknownSqlFactory', 'AnotherUnknownSqlFactory', (string) file_get_contents($caller)));
        $third = $scanner->findingsInFiles([$caller]);
        self::assertNotSame($first[0]['fingerprint'], $third[0]['fingerprint']);
        self::assertSame($first[1]['fingerprint'], $third[1]['fingerprint']);
        self::assertSame(['hits' => 1, 'misses' => 2], $scanner->projectCacheMetrics());

        unlink($caller);
        rmdir($directory);
    }

    public function test_project_index_fails_closed_when_a_scanned_source_cannot_be_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contract_ast_source_unreadable:');

        (new ContractMutationAstScanner)->findingsInFiles([sys_get_temp_dir().'/most-contract-ast-missing-'.bin2hex(random_bytes(8)).'.php']);
    }

    public function test_ast_tracks_nullable_union_statement_types_through_two_nested_function_scopes(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class NestedStatementFlow {
    public function nullable(?\PDOStatement $statement): void {
        $outer = function () use ($statement): void {
            $middle = fn () => function () use ($statement): void { $statement->execute(); };
        };
    }
    public function union(\PDOStatement|false $statement): void {
        if ($statement !== false) { $statement->execute(); }
    }
    public function intersection(\PDOStatement&StatementMarker $statement): void {
        $one = function () use ($statement): void {
            $two = function () use ($statement): void {
                $three = fn () => $statement->execute();
            };
        };
    }
}
interface StatementMarker {}
PHP);

        self::assertSame(['execute', 'execute', 'execute'], array_column($findings, 'operation'));
    }

    public function test_ast_tracks_statement_factories_in_static_injected_and_static_property_flows(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class StatementFactory {
    public static \PDOStatement $shared;
    public static function make(): \PDOStatement { return self::$shared; }
    public function build(): \PDOStatement { return self::$shared; }
}
final class StatementConsumer {
    public function __construct(private StatementFactory $factory) {}
    public function run(): void {
        StatementFactory::make()->execute();
        $this->factory->build()->execute();
        StatementFactory::$shared->execute();
    }
}
PHP);

        self::assertSame(['execute', 'execute', 'execute'], array_column($findings, 'operation'));
    }

    public function test_unknown_statement_provenance_remains_fail_closed_through_alias_array_property_and_nested_capture(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class UnknownStatementConsumer {
    private mixed $statement;
    public function run(): void {
        $statement = UnknownFactory::make();
        $statement->execute();
        $alias = $statement;
        $alias->execute();
        $bucket['statement'] = $statement;
        $bucket['statement']->execute();
        $fromArray = $bucket['statement'];
        $fromArray->execute();
        $this->statement = $fromArray;
        $this->statement->execute();
        $nested = function () use ($statement): void { $statement->execute(); };
    }
}
PHP);

        self::assertCount(6, $findings);
        foreach ($findings as $finding) {
            self::assertStringContainsString('|builder=unresolved-', $finding['fingerprint']);
        }
    }

    public function test_domain_execute_receiver_is_not_misclassified_as_unknown_statement(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class DomainToolConsumer {
    public function run(ToolRegistry $registry): void {
        $tool = $registry->getTool('report');
        $tool->execute([]);
    }
}
PHP);

        self::assertSame([], $findings);
    }

    public function test_static_statement_factory_fingerprint_tracks_its_exact_body_independently(): void
    {
        $first = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class StaticStatementFactory {
    public static function make(): \PDOStatement { throw new \RuntimeException('first'); }
}
final class StaticStatementConsumer {
    public function run(): void { StaticStatementFactory::make()->execute(); }
}
PHP);
        $second = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class StaticStatementFactory {
    public static function make(): \PDOStatement { throw new \RuntimeException('second'); }
}
final class StaticStatementConsumer {
    public function run(): void { StaticStatementFactory::make()->execute(); }
}
PHP);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
    }

    public function test_injected_statement_factory_fingerprint_tracks_only_the_exact_factory_body_and_cache_content(): void
    {
        $directory = sys_get_temp_dir().'/most-injected-statement-factory-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $caller = $directory.'/Caller.php';
        $factory = $directory.'/StatementFactory.php';
        file_put_contents($caller, <<<'PHP'
<?php
namespace InjectedStatementProject;
final class Caller {
    private StatementFactory $factory;
    public function __construct(StatementFactory $factory) { $this->factory = $factory; }
    public function run(): void { $this->factory->build()->execute(); }
}
PHP);
        $firstFactory = <<<'PHP'
<?php
namespace InjectedStatementProject;
final class StatementFactory {
    public function build(): \PDOStatement { throw new \RuntimeException('first'); }
}
PHP;
        file_put_contents($factory, $firstFactory);
        $scanner = new ContractMutationAstScanner;
        $first = $scanner->findingsInFiles([$caller, $factory]);
        file_put_contents($factory, str_replace("'first'", "'second'", $firstFactory));
        $second = $scanner->findingsInFiles([$caller, $factory]);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertStringNotContainsString('|builder=unresolved-', $first[0]['fingerprint']);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
        self::assertSame(['hits' => 0, 'misses' => 2], $scanner->projectCacheMetrics());

        unlink($caller);
        unlink($factory);
        rmdir($directory);
    }

    public function test_php_database_and_contract_identities_are_case_insensitive(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
use App\Models\Contract as MixedContractAlias;
function mutate(\aPp\mOdElS\cOnTrAcT $contract): void {
    $contract->UPDATE(['number' => 'mixed']);
    \ApP\MoDeLs\CoNtRaCt::CrEaTe(['number' => 'static']);
    MixedContractAlias::cReAtE(['number' => 'alias']);
    \iLlUmInAtE\sUpPoRt\fAcAdEs\dB::STATEMENT('UPDATE contracts SET number = 1');
}
PHP);

        self::assertSame(['UPDATE', 'CrEaTe', 'cReAtE', 'STATEMENT'], array_column($findings, 'operation'));
    }

    public function test_pdo_statement_connection_and_nested_execute_identities_are_case_insensitive(): void
    {
        $findings = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
function mutate(\pdostatement $statement, \PdO $pdo, \iLlUmInAtE\dAtAbAsE\cOnNeCtIoNiNtErFaCe $connection): void {
    $alias = $statement;
    $nested = function () use ($alias): void { $alias->EXECUTE(); };
    $pdo->EXEC('DELETE FROM contracts');
    $connection->STATEMENT('UPDATE contracts SET number = 2');
}
PHP);

        self::assertSame(['EXEC', 'STATEMENT', 'EXECUTE'], array_column($findings, 'operation'));
        self::assertStringNotContainsString('|builder=unresolved-', $findings[2]['fingerprint']);
    }

    public function test_static_and_injected_statement_factory_identities_are_case_insensitive_and_body_sensitive(): void
    {
        $first = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class MixedFactory {
    public static function StAtIcBuIlD(): \pDoStAtEmEnT { throw new \RuntimeException('first'); }
    public function InJeCtEdBuIlD(): \PdOsTaTeMeNt { throw new \RuntimeException('first'); }
}
final class MixedConsumer {
    public function __construct(private mIxEdFaCtOrY $factory) {}
    public function run(): void {
        mIxEdFaCtOrY::sTaTiCbUiLd()->ExEcUtE();
        $this->factory->iNjEcTeDbUiLd()->eXeCuTe();
    }
}
PHP);
        $second = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
final class MixedFactory {
    public static function StAtIcBuIlD(): \pDoStAtEmEnT { throw new \RuntimeException('second'); }
    public function InJeCtEdBuIlD(): \PdOsTaTeMeNt { throw new \RuntimeException('second'); }
}
final class MixedConsumer {
    public function __construct(private mIxEdFaCtOrY $factory) {}
    public function run(): void {
        mIxEdFaCtOrY::sTaTiCbUiLd()->ExEcUtE();
        $this->factory->iNjEcTeDbUiLd()->eXeCuTe();
    }
}
PHP);

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
        self::assertNotSame($first[1]['fingerprint'], $second[1]['fingerprint']);
        self::assertStringNotContainsString('|builder=unresolved-', $first[0]['fingerprint']);
        self::assertStringNotContainsString('|builder=unresolved-', $first[1]['fingerprint']);
    }

    public function test_parent_statement_factory_identity_is_case_insensitive_and_body_sensitive(): void
    {
        $first = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
class ParentStatementFactory {
    protected static function BuIlD(): \PDOStatement { throw new \RuntimeException('first'); }
}
final class ChildStatementFactory extends ParentStatementFactory {
    public static function WrAp(): \PDOStatement { return PaReNt::bUiLd(); }
}
final class ParentFactoryConsumer {
    public function run(): void { cHiLdStAtEmEnTfAcToRy::wRaP()->EXECUTE(); }
}
PHP);
        $second = (new ContractMutationAstScanner)->findings(<<<'PHP'
<?php
class ParentStatementFactory {
    protected static function BuIlD(): \PDOStatement { throw new \RuntimeException('second'); }
}
final class ChildStatementFactory extends ParentStatementFactory {
    public static function WrAp(): \PDOStatement { return PaReNt::bUiLd(); }
}
final class ParentFactoryConsumer {
    public function run(): void { cHiLdStAtEmEnTfAcToRy::wRaP()->EXECUTE(); }
}
PHP);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
        self::assertStringNotContainsString('|builder=unresolved-', $first[0]['fingerprint']);
    }

    public function test_project_statement_factory_index_is_cross_file_content_sensitive_and_unknown_call_chains_fail_closed(): void
    {
        $directory = sys_get_temp_dir().'/most-statement-factory-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $caller = $directory.'/Caller.php';
        $factory = $directory.'/StatementFactory.php';
        file_put_contents($caller, <<<'PHP'
<?php
namespace StatementProject;
final class Caller {
    public function __construct(private StatementFactory $factory) {}
    public function run(): void {
        StatementFactory::make()->execute();
        $this->factory->build()->execute();
        UnknownStatementFactory::make()->execute();
    }
}
PHP);
        $firstFactory = <<<'PHP'
<?php
namespace StatementProject;
final class StatementFactory {
    public static function make(): \PDOStatement { return self::source(); }
    public function build(): \PDOStatement { return self::source(); }
    private static function source(): \PDOStatement { throw new \RuntimeException('first'); }
}
PHP;
        file_put_contents($factory, $firstFactory);
        $scanner = new ContractMutationAstScanner;
        $first = $scanner->findingsInFiles([$caller, $factory]);
        file_put_contents($factory, str_replace("'first'", "'second'", $firstFactory));
        $second = $scanner->findingsInFiles([$caller, $factory]);

        self::assertCount(3, $first);
        self::assertCount(3, $second);
        self::assertStringContainsString('|builder=unresolved-', $first[2]['fingerprint']);
        self::assertNotSame($first[0]['fingerprint'], $second[0]['fingerprint']);
        self::assertNotSame($first[1]['fingerprint'], $second[1]['fingerprint']);
        self::assertSame($first[2]['fingerprint'], $second[2]['fingerprint']);
        self::assertSame(['hits' => 0, 'misses' => 2], $scanner->projectCacheMetrics());

        unlink($caller);
        unlink($factory);
        rmdir($directory);
    }

    public function test_structural_manifest_uses_explicit_machine_checked_evidence(): void
    {
        $source = file_get_contents(__FILE__);

        self::assertIsString($source);
        self::assertStringContainsString('|evidence=', $source);
        self::assertStringContainsString('$finding[\'evidence\']', $source);
    }
}
