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
AiBudgetGuard|claimWire|c251f47ef12ba8dd239535f724de8caab910ae7f7defcf03f7ad5d3a9137adfd|evidence=selectOne:sql=SELECT eg_claim_ai_budget_wire(?) AS claimed=1
AiBudgetGuard|pendingReconciliation|ec632cc1e72fb9b769eeab19be231ed84107697379b76eac7d4db65b7e1c4e9f|evidence=selectOne:sql=SELECT eg_mark_ai_budget_reconciliation(?) AS pending=1
AiBudgetGuard|reconcileExpired|24f4633104462dabf7e202b2545a269b0448ce4d3c0c8f8f6b033d01b8acee2b|evidence=selectOne:sql=SELECT eg_reconcile_expired_ai_budgets(?) AS reconciled=1
AiBudgetGuard|releaseBeforeWire|b2b7fa48a03817adcd92b9be0f452829c0b4220a250cae2e2b16d60d2c31b64a|evidence=selectOne:sql=SELECT eg_release_ai_budget(?) AS released=1
AiBudgetGuard|reserve|520fac2a59a1901e991ffcfd6a2ac03af421e0896fddf64fc5093d07b4862d1e|evidence=selectOne:sql=SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) AS reservation_id=1
AiBudgetGuard|settle|4ee3c7cbe866cc6d3b4ddcb5f17ffae428694e08bf4e3ccb893b9adcd226afa6|evidence=selectOne:sql=SELECT eg_settle_ai_budget(?, ?, ?) AS settled=1
BenchmarkRunRepository|lockStorageObject|99b11b03a6ff0036b5b236b18a988a7ebead9d219c874760b0a110e3ff437d44|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=1
BenchmarkRunRepository|start|7cb6c5b970db9a8ba64bee2be36395c8fe51eb1d636aa0e6b8c2ec2018a460d3|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))=1
BenchmarkRunRepository|unlockStorageObject|a5a0ce4ef33286d55189ee80e772a3f015b6d6ebbce50cd44b0b3ce1cb613be6|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=1
BuildSessionOperationalSnapshot|finalization|745d8baea2a8578d4dee6854e02dbb566721a928ed8a489e7f6cb86aea348dde|evidence=selectOne:sql=SELECT (SELECT COUNT(*) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_total, (SELECT COALESCE(MAX(id), 0) …=1
BuildSessionOperationalSnapshot|sourceWatermarks|d3fe49ee303d13a38dcce5c5c761cd14563cc52592ca93bb6c272d4ae1b3d561|evidence=selectOne:sql=SELECT (SELECT COUNT(*) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_count, (SELECT COALESCE(MAX(id), 0) FROM e…=1
ContractService|getContractsSummary|23ad2d19d2129c0c305836d1eda177aee124d3d03f6438ce5f19aef1f1e7779c|evidence=raw:argument="({$nearingLimitSubquery->toSql()})assubquery"=1
DashboardController|contractsRequiringAttention|b91510a6e47aa070ce4f800ffffbd2689284bd7e3c5535ec81351c1964797e33|evidence=raw:argument='CASEWHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=100THEN3WHENend_date<CURRENT_TIMESTAMPANDstatus=\''.\App\Enums\Contract\ContractStatusEnum::ACTIVE->value.'\'THEN2WHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=90THEN1ELSE0END'=1
DashboardController|getTopCreditors|cbca1d63729a20f11b0a3c648d331e64c2111adaa145eee4a7c6e16a9a921112|evidence=raw:sql=CASE WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id) ELSE (SELECT name FROM organizations WHERE id = payment_documents…=1
DashboardController|getTopDebtors|e41bb63fd7be2eb47debdf8ab634208114e3c74e00c92b879fc53b8a04a63aee|evidence=raw:sql=CASE WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id) ELSE (SELECT name FROM organizations WHERE id = payment_documents…=1
DatabaseNotificationCommitSequencer|run|8c8dd6db8a157032aa670b4baa6b1c790d63604848b2d98131b864ea5d836875|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))=1
EloquentBuildingModelStore|transaction|20749cf6cf1e0e8c812e60ca7a44bf791af1f225b6175bf8c6aac51302ab4958|evidence=select:sql=SELECT pg_advisory_xact_lock(?, ?)=1
EloquentEffectiveSettingsOperationStore|pin|c9d8700383b10fedb87f7393071903947ac19318e1c2772765be59010d84a1de|evidence=selectOne:sql=SELECT * FROM eg_pin_ai_operation_settings(?, ?, ?)=1
EloquentEvidenceRepository|descendantBatches|193839e971c6a3b6526cf393bae4c38c1bbd11437e9472c1abe5b3caddb906c5|evidence=insert:argument=$sql=1
EloquentEvidenceRepository|descendantBatches|193839e971c6a3b6526cf393bae4c38c1bbd11437e9472c1abe5b3caddb906c5|evidence=statement:argument="CREATETEMPTABLEIFNOTEXISTS{$temporaryTable}(idbigintPRIMARYKEY)ONCOMMITDROP"=1
EloquentEvidenceRepository|transaction|67c19101beb3f263736e92036b652acdab03b13a8a805de024dbb93ae1f61e8f|evidence=select:sql=SELECT pg_advisory_xact_lock(?, ?)=1
ErrorTrackingController|timeseries|243a18099f965abe603742d1738853241d09c83fcd639dc49e493193c07caea8|evidence=raw:argument="DATE_TRUNC('{$interval}',last_seen_at)astime"=1
EstimateGenerationPackagePersistenceService|appendItemRevision|3123c24ee142a3e3fc5c562f6ed80f16ccffd1e409dc4cbdc06198e9cec22dc9|evidence=select:sql=SELECT public.eg_finalize_package_item_price(?)=1
EstimateGenerationResourceIndexRuntime|dropAll|1de26fcf9c7e4caa8a0c78636b617c297fe3f7a3088e6ecbb0416703c1b0dc3b|evidence=statement:argument=$index['dropIfExists']=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|08ab7c94420ac6bd9a27b366703e7a4bd15a9f2c7fb2c42117251a67cb4a4c64|evidence=statement:argument=$index['create']=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|08ab7c94420ac6bd9a27b366703e7a4bd15a9f2c7fb2c42117251a67cb4a4c64|evidence=statement:argument=$index['drop']=1
EstimateGenerationResourceIndexRuntime|findIndex|4c9ad128f990e5a8e40d3f43622b1a8fa43cba30f2e517735c835693ea313f69|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, pg_get_indexdef(c.oid) AS definition FROM pg_class AS c INNER JOIN pg_namespace AS n ON n.oid = c.relnamespace INNER JOIN pg_index AS i ON i.inde…=1
EstimateGenerationReviewQueueQuery|paginate|c0331079583c31b1ce154250ea4530165569f3ae0bdf0aa80c81d2a8568cb304|evidence=select:argument=$this->pageSql($where)=1
EstimateGenerationReviewQueueQuery|paginate|fd553e1c913c168b870c32fbbe85c6a132231ff9f2b1c04be96431940399dae2|evidence=selectOne:argument=$this->summarySql($where)=1
EstimateGenerationTrainingDatasetService|appendVersion|7da0fb97e423e08f7b4fdab06e61506d4458bc77ace32da405d235dc4d0635c3|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))=1
GeometryDependencyInvalidator|invalidate|462a2d90538872650976351ff6711bad4081097e39d48e2fe8762d6db78fa727|evidence=select:argument='WITHRECURSIVEdescendants(id)AS(SELECTchild_idFROMestimate_generation_evidence_edgesWHEREsession_id=?ANDparent_idIN('.implode(',',array_fill(0,$roots->count(),'?')).')UNIONSELECTedge.child_idFROMestimate_generation_evidence_edgesedgeJOINdescendantstreeONtree.id=edge.parent_idWHEREedge.session_id=?)SELECTidFROMdescendants'=1
HoldingReportService|getContractsByContractor|72cfb1fd79414a817069f3aaf9529365100005e10344fe2dd31b1e566d6ee5f3|evidence=raw:argument="({$query->toSql()})assub"=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|9a92dbbba1d6f22dfa1d20013e76f924016abdd8cde7181a120f566d4b89a641|evidence=statement:argument="CREATEUNIQUEINDEXCONCURRENTLY{$name}ONimmutable_audit_events(".implode(',',$columns).")WHERE{$predicate}"=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|9a92dbbba1d6f22dfa1d20013e76f924016abdd8cde7181a120f566d4b89a641|evidence=statement:argument="DROPINDEXCONCURRENTLYIFEXISTS{$name}"=1
ImmutableAuditPhaseBInvariantService|functionCatalog|39222f16e4f6b21e8dfe2aa481aa9c8da38a717745a61cef8b274540f08e885f|evidence=selectOne:sql=SELECT p.prosrc, pg_get_function_identity_arguments(p.oid) AS identity_arguments, pg_get_function_result(p.oid) AS result, l.lanname AS language, p.provolatile AS volatility, p.pro…=1
ImmutableAuditPhaseBInvariantService|index|8533f18a4db53d84bd1cb91bcc1591f855827e92566e8c8b44c501cfedcbc246|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, i.indisunique, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND …=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|01a8868817a2d81a8e551219573a3be644947d8c14ef6cca96dcb8f61a9d8fd8|evidence=unprepared:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::canonicalCoreSql()=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|071e1e1c15a0a8a7698334a4d39c32b3da042385e727954e7a65f58295df4925|evidence=statement:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::SEQUENCE_ALTER_SQL=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|071e1e1c15a0a8a7698334a4d39c32b3da042385e727954e7a65f58295df4925|evidence=statement:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::SEQUENCE_CREATE_SQL=1
ImmutableAuditPhaseBInvariantService|sequenceCatalog|f35e630b2184d2cbb568655972f85a449c69fb2b99dc2dbee26b5bab27c9776b|evidence=selectOne:sql=SELECT s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by, s.cycle, s.cache_size, c.relname AS owned_table, a.attname AS owned_column FROM pg_sequences s JOIN pg_…=1
ImmutableAuditPhaseBInvariantService|triggerCatalog|72ce81a0150c0d7ddd48929d95abb85a6c4dd51a69ed2a3c77ffa593c9694e15|evidence=selectOne:sql=SELECT t.tgname AS name, t.tgenabled AS enabled, t.tgisinternal AS internal, c.relname AS relation, p.proname AS function_name, t.tgtype AS type, pg_get_triggerdef(t.oid, true) AS …=1
ImmutableAuditRolloutService|cutover|86e4e5b604d45cefffabb63b0b368e392b540f163d726516721ff75e584b835a|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|cutover|86e4e5b604d45cefffabb63b0b368e392b540f163d726516721ff75e584b835a|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|cutover|86e4e5b604d45cefffabb63b0b368e392b540f163d726516721ff75e584b835a|evidence=statement:sql=SELECT setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1)), EXISTS (S…=1
ImmutableAuditRolloutService|ensurePhaseBIndex|a286638bc3eab9dc9f1670ca7325ee751b172b94689d4a2034b0691f036f84f2|evidence=statement:argument="CREATEUNIQUEINDEXCONCURRENTLY{$name}ONimmutable_audit_events({$columnSql})WHERE{$predicate}"=1
ImmutableAuditRolloutService|ensurePhaseBIndex|a286638bc3eab9dc9f1670ca7325ee751b172b94689d4a2034b0691f036f84f2|evidence=statement:argument="DROPINDEXCONCURRENTLYIFEXISTS{$name}"=1
ImmutableAuditRolloutService|lockedRolloutMarker|3a658c0c9a9e93c8918b614ebf54976e10b69c133f551a5eb8b75969cc3113f9|evidence=selectOne:argument=<<<SQLSELECTphase,writer_version,writer_credential_hash,drain_marker,drain_confirmed_at,drain_confirmed_atISNOTNULLANDdrain_confirmed_at>=clock_timestamp()-make_interval(mins=>CAST(?ASinteger))ASdrain_freshFROMimmutable_audit_rolloutWHEREsingleton=true{$forUpdateSql}SQL=1
ImmutableAuditRolloutService|repairPermanentInvariants|7fde919b17ecdd036eb3fe906f5c51befabafb900c6beec77a40e78e8ef2abf7|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|repairPermanentInvariants|7fde919b17ecdd036eb3fe906f5c51befabafb900c6beec77a40e78e8ef2abf7|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=2
LaravelNotificationSnapshotDatabase|statement|344a1fc33f0ec99fed10c711f5ae406a26c1c610961fed4a6120b5895be5360c|evidence=statement:argument=$sql=1
NormativeRetrievalRolloutService|deploy|5d09553531c2a3f09b559404d1951414acde84d3b9810264316f52d57e69bc64|evidence=select:sql=SELECT pg_advisory_unlock(hashtext('normative-retrieval-v1'))=1
NormativeRetrievalRolloutService|deploy|5d09553531c2a3f09b559404d1951414acde84d3b9810264316f52d57e69bc64|evidence=selectOne:sql=SELECT pg_try_advisory_lock(hashtext('normative-retrieval-v1')) AS locked=1
NormativeRetrievalRolloutService|deploy|5d09553531c2a3f09b559404d1951414acde84d3b9810264316f52d57e69bc64|evidence=unprepared:sql=DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norm_semantic_score_ck') THEN ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_…=1
NormativeRetrievalRolloutService|deploy|5d09553531c2a3f09b559404d1951414acde84d3b9810264316f52d57e69bc64|evidence=unprepared:sql=DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norms_validity_ck') THEN ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (…=1
NotificationQueryService|unreadAggregatesForQuery|5b8d33f2b7a68e023452ab5bafb2ee1ae1ee3db1182c7ff42bcdd0dfcf1d271b|evidence=raw:argument=$categoryExpression=1
NotificationQueryService|unreadAggregatesForQuery|5b8d33f2b7a68e023452ab5bafb2ee1ae1ee3db1182c7ff42bcdd0dfcf1d271b|evidence=raw:argument=$notificationTypeExpression=1
NotificationQueryService|unreadAggregatesForQuery|5b8d33f2b7a68e023452ab5bafb2ee1ae1ee3db1182c7ff42bcdd0dfcf1d271b|evidence=raw:argument=$typeExpression=1
PackageInputVersionBackfill|run|3a49a5c4cfc021668d32895859a4c87e80683a77bc030d208f28d07ee0d9d404|evidence=affectingStatement:argument=self::SQL=1
PaymentDocumentService|generateDocumentNumber|55875858fff0b2c1f766826471c663efea4ae63c8d468187112665fc98479b96|evidence=selectOne:sql=SELECT get_next_payment_document_number(?, ?, ?, ?) as number=1
PostgresNormativeCandidateSource|find|a095a6f2fec854a0362e6030cb903a70aef0c30c9305a7c15f5d1b87b6730995|evidence=select:argument=self::QUERY_CONTRACT=1
RagIndexer|storeVector|17082e9a98eba1d467b708e3c6f4ebeea539908997f648a44dee101dbd69ae21|evidence=update:argument=$sql=1
RagRetriever|postgresRows|1f683253f1209ddc3d779a8969be392b59ccb6e2ece920e58ceb6c185cd7987d|evidence=select:argument=$sql=1
ReportService|getContractPaymentsReport|318423f31432042bceca3fe18d061b4afd6cae479a4e839da45aa9174d376ed4|evidence=raw:argument='(SELECTCOALESCE(SUM(amount),0)FROMcontract_performance_actsWHEREcontract_id=contracts.idANDproject_id='.$projectId.'ANDis_approved=true)ascompleted_amount'=1
ReportService|getContractPaymentsReport|318423f31432042bceca3fe18d061b4afd6cae479a4e839da45aa9174d376ed4|evidence=raw:argument='(SELECTCOALESCE(SUM(paid_amount),0)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)aspaid_amount'=1
ReportService|getContractorSettlementsReport|85c9ceaae1980281a7cf2d08518d49a3be64704a313108e19bccd3ff91603e30|evidence=raw:argument=$completedAmountSubquery=1
ReportService|getContractorSettlementsReport|85c9ceaae1980281a7cf2d08518d49a3be64704a313108e19bccd3ff91603e30|evidence=raw:argument='COALESCE(SUM((SELECTSUM(paid_amount)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)),0)astotal_paid'=1
ReportService|getProjectProfitabilityReport|1acd67f2b740530936089bdad7474639b634400b2548832c77c97c08c7084788|evidence=raw:argument='(SELECTCOALESCE(SUM(quantity*price),0)FROMwarehouse_movementsWHEREproject_id=projects.idANDwarehouse_movements.organization_id='.$organizationId.'ANDmovement_type=\'receipt\')asmaterial_costs'=1
ReportService|getProjectProfitabilityReport|1acd67f2b740530936089bdad7474639b634400b2548832c77c97c08c7084788|evidence=raw:argument='(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')ascontractor_costs'=1
ReportService|getProjectTimelinesReport|cfd73b7fe6f588a7753bb88833ee4bbf8ef10d834a881ac6c9710824b96979c8|evidence=raw:argument='(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')astotal_contract_amount'=1
ResetInvoiceNumberSequences|handle|d8457900533367444ff7fd3548388296d368892ec1a3a2abc3837883b7a88ff5|evidence=statement:argument="DROPSEQUENCEIFEXISTS".$seq->relname=1
ResetPaymentDocumentSequences|handle|4d3e5874c1caf215f839c1b1d92ff71562a0b9b3e60b53aa80296192a98ae02f|evidence=statement:argument="DROPSEQUENCEIFEXISTS".$seq->relname=1
SearchService|searchNearby|838534698060e4c7033bc3fc811425c92df56d67ef7d030049f72648c0db80ed|evidence=select:sql=SELECT id, name, address, latitude, longitude, status, budget_amount, (6371 * acos( cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)…=1
SqlEstimateGenerationDashboardRepository|all|0dda1cb4a7e6337c49d1928b4d7cfbde7359e2429bb6ceca450fee4acc319536|evidence=select:argument=$query->sql=1
SqlEstimateGenerationDashboardRepository|one|3ad3b6d339e8052349854582b0ad713a5f59123d2e3161c3391cc589c6a10374|evidence=selectOne:argument=$query->sql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=select:sql=SELECT c.relname, pg_get_indexdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN (?, ?)=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, i.indisunique, ns.nspname AS schema_name, tbl.relname AS table_name, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum,…=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=statement:argument=$createSql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=statement:argument='DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$name=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=statement:argument='DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$probe=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=statement:argument='DROPINDEXCONCURRENTLYIFEXISTS'.$expectedSchema.'.'.$probe=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|3edf28cd7633594c5c3cb4282ad3d2154f3aa1f544ddf42ab1b2c25bdd8a7365|evidence=statement:argument=(string)$probeSql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|0462613aaa5a1ac5e907ed0361662c0d6676e0040f6434bcf05b536453d52d19|evidence=selectOne:sql=SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? …=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|0462613aaa5a1ac5e907ed0361662c0d6676e0040f6434bcf05b536453d52d19|evidence=selectOne:sql=SELECT pg_get_constraintdef(c.oid, true) AS definition, c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHER…=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|0462613aaa5a1ac5e907ed0361662c0d6676e0040f6434bcf05b536453d52d19|evidence=statement:argument="ALTERTABLE{$qualified}ADDCONSTRAINT{$name}{$definition}NOTVALID"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|0462613aaa5a1ac5e907ed0361662c0d6676e0040f6434bcf05b536453d52d19|evidence=statement:argument="ALTERTABLE{$qualified}ADDCONSTRAINT{$probe}{$definition}NOTVALID"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|0462613aaa5a1ac5e907ed0361662c0d6676e0040f6434bcf05b536453d52d19|evidence=statement:argument="ALTERTABLE{$qualified}DROPCONSTRAINTIFEXISTS{$probe}"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|0462613aaa5a1ac5e907ed0361662c0d6676e0040f6434bcf05b536453d52d19|evidence=statement:argument="ALTERTABLE{$qualified}DROPCONSTRAINT{$probe}"=1
TrainingBenchmarkOnlineMigrationRuntime|restoreSessionTimeouts|7b88628ce28f3e4a05031a5ab511877bf0b8a439181998b9df6ea3dd234c11d2|evidence=select:sql=SELECT set_config('lock_timeout', ?, false), set_config('statement_timeout', ?, false)=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|98e33037b647f9c0a0c45834231d138f0226ecf37d74c1bb36631c4b9905c7eb|evidence=statement:argument="ALTERTABLE{$schema}.{$table}DROPCONSTRAINTIFEXISTS{$finalName}"=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|98e33037b647f9c0a0c45834231d138f0226ecf37d74c1bb36631c4b9905c7eb|evidence=statement:argument="ALTERTABLE{$schema}.{$table}RENAMECONSTRAINT{$temporaryName}TO{$finalName}"=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|98e33037b647f9c0a0c45834231d138f0226ecf37d74c1bb36631c4b9905c7eb|evidence=statement:argument="LOCKTABLE{$schema}.{$table}INACCESSEXCLUSIVEMODE"=1
TrainingBenchmarkOnlineMigrationRuntime|validateConstraint|86baf3713d3f40a6f41048d08be3c5b9de080b7bef5b412dcfaaa98725d7adff|evidence=statement:argument="ALTERTABLE{$schema}.{$table}VALIDATECONSTRAINT{$name}"=1
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

    public function test_structural_manifest_uses_explicit_machine_checked_evidence(): void
    {
        $source = file_get_contents(__FILE__);

        self::assertIsString($source);
        self::assertStringContainsString('|evidence=', $source);
        self::assertStringContainsString('$finding[\'evidence\']', $source);
    }
}
