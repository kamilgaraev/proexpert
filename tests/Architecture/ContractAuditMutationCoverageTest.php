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
AiBudgetGuard|claimWire|b710d1c389a6495ad169941cd907a1c26ff5fef0dae188d1c40016edf0997833|evidence=selectOne:sql=SELECT eg_claim_ai_budget_wire(?) AS claimed=1
AiBudgetGuard|pendingReconciliation|6debcae6c3eb2657edd03ef0abb4d3a898d082d0bc2978a9d8018346a9a9b083|evidence=selectOne:sql=SELECT eg_mark_ai_budget_reconciliation(?) AS pending=1
AiBudgetGuard|reconcileExpired|00bc9d63e0a0abcae7880105aa6b577c49c571dad1408552ebb34f19e8bf1d31|evidence=selectOne:sql=SELECT eg_reconcile_expired_ai_budgets(?) AS reconciled=1
AiBudgetGuard|releaseBeforeWire|62f51849ed9dc25cc455143b6ce24c22fe4f41fae226f32f25db28bb62838228|evidence=selectOne:sql=SELECT eg_release_ai_budget(?) AS released=1
AiBudgetGuard|reserve|f0470cbff6f259a9579d38de7f7228fdd1af49b9edc1227d2bf408f18336353e|evidence=selectOne:sql=SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) AS reservation_id=1
AiBudgetGuard|settle|b55421553cf6695aa7f2fe1e2dbc1f02f9b7d2ff291470fd3d8bb2365b482f9c|evidence=selectOne:sql=SELECT eg_settle_ai_budget(?, ?, ?) AS settled=1
BenchmarkRunRepository|lockStorageObject|4e3b5c1a3210f23d6964c4ebd9e66c8ff876fbf4314f62184788ecd0d561a648|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=1
BenchmarkRunRepository|start|a52af3c4b757ed68fdfee3e06912436f15afc28149b409a4c4d251c75d9f228c|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))=1
BenchmarkRunRepository|unlockStorageObject|c54f912a655d069b605f8f75742af831f972381348cffe18d7dfc65ddd2dbfff|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=1
BuildSessionOperationalSnapshot|finalization|c90222c61cacbb3223c0067b1f736a808db3830e798c9ca288bf69d0a7722799|evidence=selectOne:sql=SELECT (SELECT COUNT(*) FROM estimate_generation_finalization_outbox WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS outbox_total, (SELECT COALESCE(MAX(id), 0) …=1
BuildSessionOperationalSnapshot|sourceWatermarks|eecbaed5358b03a4bca3ba0f9eec63c208ff870df9ad9c69a7b6f46fcbbecb95|evidence=selectOne:sql=SELECT (SELECT COUNT(*) FROM estimate_generation_document_pages WHERE organization_id = ? AND project_id = ? AND session_id = ?) AS pages_count, (SELECT COALESCE(MAX(id), 0) FROM e…=1
ContractService|getContractsSummary|275b34d934e8328dd741061979b1d6413de710c06ac0181cba75390908666d05|evidence=raw:argument="({$nearingLimitSubquery->toSql()})assubquery"=1
DashboardController|contractsRequiringAttention|e99332b48903c576af70fde439e47bf3b61effd977ea4db1a83c73f9945485e1|evidence=raw:argument='CASEWHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=100THEN3WHENend_date<CURRENT_TIMESTAMPANDstatus=\''.\App\Enums\Contract\ContractStatusEnum::ACTIVE->value.'\'THEN2WHEN(CASEWHENcontracts.total_amount>0THENROUND((COALESCE(cw.completed_amount,0)/contracts.total_amount)*100,2)ELSE0END)>=90THEN1ELSE0END'=1
DashboardController|getTopCreditors|27f98e0d50561d965559595aa1840e0e2d061de0723c1aa0f53cb49a53d18497|evidence=raw:sql=CASE WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id) ELSE (SELECT name FROM organizations WHERE id = payment_documents…=1
DashboardController|getTopDebtors|5e60110e7897032fa7494289da388d0a2d2fa50cc9528f9df89253d2055d1626|evidence=raw:sql=CASE WHEN contractor_id IS NOT NULL THEN (SELECT name FROM contractors WHERE id = payment_documents.contractor_id) ELSE (SELECT name FROM organizations WHERE id = payment_documents…=1
DatabaseNotificationCommitSequencer|run|df9ef9dbcffebf7d22688e1fb8e2f7a16e32fad5581e26c569dceee658269bfb|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))=1
EloquentBuildingModelStore|transaction|d59d897706620ec9ed355f77fb1ebdba20e6db22f1893b4ef8f10dcd41e00060|evidence=select:sql=SELECT pg_advisory_xact_lock(?, ?)=1
EloquentEffectiveSettingsOperationStore|pin|9f522fe3215c8a41f197080108aeab7e79f58af805d2cd6e22cd6b57b716c749|evidence=selectOne:sql=SELECT * FROM eg_pin_ai_operation_settings(?, ?, ?)=1
EloquentEvidenceRepository|descendantBatches|ee8ccfc1b6ef0ad33e13ca541b692e1159be87b25acc76c462188c8bfbccb463|evidence=insert:argument=$sql=1
EloquentEvidenceRepository|descendantBatches|ee8ccfc1b6ef0ad33e13ca541b692e1159be87b25acc76c462188c8bfbccb463|evidence=statement:argument="CREATETEMPTABLEIFNOTEXISTS{$temporaryTable}(idbigintPRIMARYKEY)ONCOMMITDROP"=1
EloquentEvidenceRepository|transaction|33323c073328b96f20f87bca6ed2accc574059f5dcaf59b222f877bff0734f7f|evidence=select:sql=SELECT pg_advisory_xact_lock(?, ?)=1
ErrorTrackingController|timeseries|cf6c4ea76cc3d37ddd889f1ccab688b95ec8183c644c3b0d32904da1f7af833b|evidence=raw:argument="DATE_TRUNC('{$interval}',last_seen_at)astime"=1
EstimateGenerationPackagePersistenceService|appendItemRevision|b9696d173e3374f3dd2bdd467ce5825eb8e79dc57f03067af578d5cc573bcac3|evidence=select:sql=SELECT public.eg_finalize_package_item_price(?)=1
EstimateGenerationResourceIndexRuntime|dropAll|e51385df84c50ee03f34c65fedd9c0e1a2999a2c6c132a0cd2e7ec0f09580e34|evidence=statement:argument=$index['dropIfExists']=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|a88c48bb873a8bd6039f1fb4d1f506e857de3632c7621b3f55c8ee029a95daa9|evidence=statement:argument=$index['create']=1
EstimateGenerationResourceIndexRuntime|ensureConcurrentIndex|a88c48bb873a8bd6039f1fb4d1f506e857de3632c7621b3f55c8ee029a95daa9|evidence=statement:argument=$index['drop']=1
EstimateGenerationResourceIndexRuntime|findIndex|f0cbd68cde64f801fb43f4e300942d5d046a2ccdb1f0348bd132b221d4dac982|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, pg_get_indexdef(c.oid) AS definition FROM pg_class AS c INNER JOIN pg_namespace AS n ON n.oid = c.relnamespace INNER JOIN pg_index AS i ON i.inde…=1
EstimateGenerationReviewQueueQuery|paginate|5c3a2c3c09f0ed2e288df96364ed245e80897b855f006716d77d18521d0858ac|evidence=select:argument=$this->pageSql($where)=1
EstimateGenerationReviewQueueQuery|paginate|8fa9de20ce274c21b98b6ab5bcb7ca9f8d9a44c0c4cecda34911c284ec86be01|evidence=selectOne:argument=$this->summarySql($where)=1
EstimateGenerationTrainingDatasetService|appendVersion|68091d6e05c874a50d0205df755c1540acd5be9539b452b36d1aec61f7be7ee6|evidence=select:sql=SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))=1
GeometryDependencyInvalidator|invalidate|3c312851b4d2491828a0183ae19c2106c1155667f2977c02d34e2926b5f3fe83|evidence=select:argument='WITHRECURSIVEdescendants(id)AS(SELECTchild_idFROMestimate_generation_evidence_edgesWHEREsession_id=?ANDparent_idIN('.implode(',',array_fill(0,$roots->count(),'?')).')UNIONSELECTedge.child_idFROMestimate_generation_evidence_edgesedgeJOINdescendantstreeONtree.id=edge.parent_idWHEREedge.session_id=?)SELECTidFROMdescendants'=1
HoldingReportService|getContractsByContractor|a2dc8e5a7839d895419f49b51d8d408934ed28a54822619fe7446991cbef36e8|evidence=raw:argument="({$query->toSql()})assub"=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|4c28aef85f5a9d7813518a84332aec068c04013ee63b4d16d92948a39e7e0f3c|evidence=statement:argument="CREATEUNIQUEINDEXCONCURRENTLY{$name}ONimmutable_audit_events(".implode(',',$columns).")WHERE{$predicate}"=1
ImmutableAuditPhaseBInvariantService|ensurePhaseBIndex|4c28aef85f5a9d7813518a84332aec068c04013ee63b4d16d92948a39e7e0f3c|evidence=statement:argument="DROPINDEXCONCURRENTLYIFEXISTS{$name}"=1
ImmutableAuditPhaseBInvariantService|functionCatalog|68b5e25502a6c292d156833338fa436d734af522a4f9d33608051911ce2834e1|evidence=selectOne:sql=SELECT p.prosrc, pg_get_function_identity_arguments(p.oid) AS identity_arguments, pg_get_function_result(p.oid) AS result, l.lanname AS language, p.provolatile AS volatility, p.pro…=1
ImmutableAuditPhaseBInvariantService|index|dbb4d904dcd839a20ce72f15a99b961408b583f14667278c27df8cab3321ab86|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, i.indisunique, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord) JOIN pg_attribute a ON a.attrelid = i.indrelid AND …=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|e587b69b301c0d6a2a7bd43cf8d71e13a03eb880383fd13a7f76c1905cbca24e|evidence=statement:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::SEQUENCE_ALTER_SQL=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|e587b69b301c0d6a2a7bd43cf8d71e13a03eb880383fd13a7f76c1905cbca24e|evidence=statement:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::SEQUENCE_CREATE_SQL=1
ImmutableAuditPhaseBInvariantService|installCanonicalCore|e587b69b301c0d6a2a7bd43cf8d71e13a03eb880383fd13a7f76c1905cbca24e|evidence=unprepared:argument=\App\BusinessModules\Core\ImmutableAudit\Support\ImmutableAuditInvariantDefinitions::canonicalCoreSql()=1
ImmutableAuditPhaseBInvariantService|sequenceCatalog|b3b5d2badb35de7dc2c8ec5a812c9bc9c30dbc24cd2d92fbfe14800ff62d1dff|evidence=selectOne:sql=SELECT s.data_type, s.start_value, s.min_value, s.max_value, s.increment_by, s.cycle, s.cache_size, c.relname AS owned_table, a.attname AS owned_column FROM pg_sequences s JOIN pg_…=1
ImmutableAuditPhaseBInvariantService|triggerCatalog|7e5379ff1e3a3c8869475b65b043c84566dcaf56aad0fde3a4db805f7e23eab7|evidence=selectOne:sql=SELECT t.tgname AS name, t.tgenabled AS enabled, t.tgisinternal AS internal, c.relname AS relation, p.proname AS function_name, t.tgtype AS type FROM pg_trigger t JOIN pg_class c O…=1
ImmutableAuditRolloutService|cutover|f1e58a0a48fec74555f8c78638e2a0a47dae07da4ceee2691b703c4b847a0c46|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|cutover|f1e58a0a48fec74555f8c78638e2a0a47dae07da4ceee2691b703c4b847a0c46|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|cutover|f1e58a0a48fec74555f8c78638e2a0a47dae07da4ceee2691b703c4b847a0c46|evidence=statement:sql=SELECT setval('immutable_audit_sequence', GREATEST((SELECT last_value FROM immutable_audit_sequence), COALESCE((SELECT MAX(sequence_id) FROM immutable_audit_events), 1)), EXISTS (S…=1
ImmutableAuditRolloutService|ensurePhaseBIndex|853a1c383b98d5c3c7fa8ec1770af3af0111500f903d0d43b966f3b950e69ad8|evidence=statement:argument="CREATEUNIQUEINDEXCONCURRENTLY{$name}ONimmutable_audit_events({$columnSql})WHERE{$predicate}"=1
ImmutableAuditRolloutService|ensurePhaseBIndex|853a1c383b98d5c3c7fa8ec1770af3af0111500f903d0d43b966f3b950e69ad8|evidence=statement:argument="DROPINDEXCONCURRENTLYIFEXISTS{$name}"=1
ImmutableAuditRolloutService|lockedRolloutMarker|ec71deb5161aa9bf9a2443f569ed589c4500dd78cf61e6bff84a044c41c37016|evidence=selectOne:argument=<<<SQLSELECTphase,writer_version,writer_credential_hash,drain_marker,drain_confirmed_at,drain_confirmed_atISNOTNULLANDdrain_confirmed_at>=clock_timestamp()-make_interval(mins=>CAST(?ASinteger))ASdrain_freshFROMimmutable_audit_rolloutWHEREsingleton=true{$forUpdateSql}SQL=1
ImmutableAuditRolloutService|repairPermanentInvariants|e79de7f9a4a85ce3f2dea5bda1d18a67d705f19c4edb11611e443c46adea2d9c|evidence=select:sql=SELECT pg_advisory_lock(hashtextextended(?, 0))=2
ImmutableAuditRolloutService|repairPermanentInvariants|e79de7f9a4a85ce3f2dea5bda1d18a67d705f19c4edb11611e443c46adea2d9c|evidence=select:sql=SELECT pg_advisory_unlock(hashtextextended(?, 0))=2
LaravelNotificationSnapshotDatabase|statement|e31dd57e7ead1dc05e8013895f056ac0ddbb46cd84914fd37a2fa15c26d4f410|evidence=statement:argument=$sql=1
NormativeRetrievalRolloutService|deploy|923dc1c4b5e3ca7017bf8620e69893f8eb9a1a1928a96de60e199863e6e3b90d|evidence=select:sql=SELECT pg_advisory_unlock(hashtext('normative-retrieval-v1'))=1
NormativeRetrievalRolloutService|deploy|923dc1c4b5e3ca7017bf8620e69893f8eb9a1a1928a96de60e199863e6e3b90d|evidence=selectOne:sql=SELECT pg_try_advisory_lock(hashtext('normative-retrieval-v1')) AS locked=1
NormativeRetrievalRolloutService|deploy|923dc1c4b5e3ca7017bf8620e69893f8eb9a1a1928a96de60e199863e6e3b90d|evidence=unprepared:sql=DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norm_semantic_score_ck') THEN ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_…=1
NormativeRetrievalRolloutService|deploy|923dc1c4b5e3ca7017bf8620e69893f8eb9a1a1928a96de60e199863e6e3b90d|evidence=unprepared:sql=DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='estimate_norms_validity_ck') THEN ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (…=1
NotificationQueryService|unreadAggregatesForQuery|bd3d7b41ea6b870b8b413843779d8e0879d0f1c2fd5ca63721007f8949b8e7f8|evidence=raw:argument=$categoryExpression=1
NotificationQueryService|unreadAggregatesForQuery|bd3d7b41ea6b870b8b413843779d8e0879d0f1c2fd5ca63721007f8949b8e7f8|evidence=raw:argument=$notificationTypeExpression=1
NotificationQueryService|unreadAggregatesForQuery|bd3d7b41ea6b870b8b413843779d8e0879d0f1c2fd5ca63721007f8949b8e7f8|evidence=raw:argument=$typeExpression=1
PackageInputVersionBackfill|run|61eafaab6dba09cefec68e1e3210e7c26400b8b5770b5aaf759e44917df5c914|evidence=affectingStatement:argument=self::SQL=1
PaymentDocumentService|generateDocumentNumber|f7736fe7dec437db309c0e1e7b80236c70d1b831097cc7f192bbdd4aea68a783|evidence=selectOne:sql=SELECT get_next_payment_document_number(?, ?, ?, ?) as number=1
PostgresNormativeCandidateSource|find|3909962838ec33b489afe0927232576a0b42e230a34e12357a37fc608a6fe2c0|evidence=select:argument=self::QUERY_CONTRACT=1
RagIndexer|storeVector|efdfee33b1cb7cfeaace74dfa6c498314f24ef90af148514ac7221f22ee03243|evidence=update:argument=$sql=1
RagRetriever|postgresRows|55f6209d2ccd56f015e56b06c1f4f9b48cc6c18691e9a663cf1d789a59fe680a|evidence=select:argument=$sql=1
ReportService|getContractPaymentsReport|34ffe18995660ed553161f58a62ae5bfa66e10afba283d2e7af443f1f85aab2b|evidence=raw:argument='(SELECTCOALESCE(SUM(amount),0)FROMcontract_performance_actsWHEREcontract_id=contracts.idANDproject_id='.$projectId.'ANDis_approved=true)ascompleted_amount'=1
ReportService|getContractPaymentsReport|34ffe18995660ed553161f58a62ae5bfa66e10afba283d2e7af443f1f85aab2b|evidence=raw:argument='(SELECTCOALESCE(SUM(paid_amount),0)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)aspaid_amount'=1
ReportService|getContractorSettlementsReport|dec3402eb84eb7f222613ade5808fed79980313b20cab3bddd3cc19ecb9596bc|evidence=raw:argument=$completedAmountSubquery=1
ReportService|getContractorSettlementsReport|dec3402eb84eb7f222613ade5808fed79980313b20cab3bddd3cc19ecb9596bc|evidence=raw:argument='COALESCE(SUM((SELECTSUM(paid_amount)FROMpayment_documentsWHEREinvoiceable_type=\'App\\\\Models\\\\Contract\'ANDinvoiceable_id=contracts.idANDpayment_documents.organization_id='.$organizationId.'ANDdeleted_atISNULL)),0)astotal_paid'=1
ReportService|getProjectProfitabilityReport|e40bf7197113cabc43b60f73d9c6faa5319304e0b4e324b1381e5ffb1c33b338|evidence=raw:argument='(SELECTCOALESCE(SUM(quantity*price),0)FROMwarehouse_movementsWHEREproject_id=projects.idANDwarehouse_movements.organization_id='.$organizationId.'ANDmovement_type=\'receipt\')asmaterial_costs'=1
ReportService|getProjectProfitabilityReport|e40bf7197113cabc43b60f73d9c6faa5319304e0b4e324b1381e5ffb1c33b338|evidence=raw:argument='(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')ascontractor_costs'=1
ReportService|getProjectTimelinesReport|db7cf9e1ed8f4291d288144cbb75104e6e58d574cd08ce49d374c9f154c27e11|evidence=raw:argument='(SELECTCOALESCE(SUM(total_amount),0)FROMcontractsWHEREproject_id=projects.idANDcontracts.organization_id='.$organizationId.')astotal_contract_amount'=1
ResetInvoiceNumberSequences|handle|b0d51b1ecc6d0f24dcc4b172a9cde1aabdb6d6d2521fa2ff6ed15b4c5b7c10b2|evidence=statement:argument="DROPSEQUENCEIFEXISTS".$seq->relname=1
ResetPaymentDocumentSequences|handle|d5a5a7abf9a12d0ab634192fbe681181c9b3d3e7d3a48ef002983620fe14240e|evidence=statement:argument="DROPSEQUENCEIFEXISTS".$seq->relname=1
SearchService|searchNearby|0425294d77807817d91f619c8077a8e2062434d795626d800e4b27422f8272fc|evidence=select:sql=SELECT id, name, address, latitude, longitude, status, budget_amount, (6371 * acos( cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)…=1
SqlEstimateGenerationDashboardRepository|all|2458b87dba39eff1e3a8ee75d959066d0e0a259fa55ba3b6ed3d49dd1730aa87|evidence=select:argument=$query->sql=1
SqlEstimateGenerationDashboardRepository|one|b4d0fc0998e67fd23e74da54452d8096ed0b5863ffccbd70d36cfb7522b494fb|evidence=selectOne:argument=$query->sql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=select:sql=SELECT c.relname, pg_get_indexdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname IN (?, ?)=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=selectOne:sql=SELECT i.indisvalid, i.indisready, i.indisunique, ns.nspname AS schema_name, tbl.relname AS table_name, ARRAY(SELECT a.attname FROM unnest(i.indkey) WITH ORDINALITY AS keys(attnum,…=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=statement:argument=$createSql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=statement:argument='DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$name=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=statement:argument='DROPINDEXCONCURRENTLY'.$expectedSchema.'.'.$probe=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=statement:argument='DROPINDEXCONCURRENTLYIFEXISTS'.$expectedSchema.'.'.$probe=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConcurrentIndex|bcf8c3fb218f300bce00f08551bff1493f5054ecafbede068fc59d936bd5c657|evidence=statement:argument=(string)$probeSql=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|9be82d0b499ac1021f3e14981ee3c2ba87ec65a586cb3099d477badb31ef4d19|evidence=selectOne:sql=SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = ? …=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|9be82d0b499ac1021f3e14981ee3c2ba87ec65a586cb3099d477badb31ef4d19|evidence=selectOne:sql=SELECT pg_get_constraintdef(c.oid, true) AS definition, c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHER…=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|9be82d0b499ac1021f3e14981ee3c2ba87ec65a586cb3099d477badb31ef4d19|evidence=statement:argument="ALTERTABLE{$qualified}ADDCONSTRAINT{$name}{$definition}NOTVALID"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|9be82d0b499ac1021f3e14981ee3c2ba87ec65a586cb3099d477badb31ef4d19|evidence=statement:argument="ALTERTABLE{$qualified}ADDCONSTRAINT{$probe}{$definition}NOTVALID"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|9be82d0b499ac1021f3e14981ee3c2ba87ec65a586cb3099d477badb31ef4d19|evidence=statement:argument="ALTERTABLE{$qualified}DROPCONSTRAINTIFEXISTS{$probe}"=1
TrainingBenchmarkOnlineMigrationRuntime|ensureConstraint|9be82d0b499ac1021f3e14981ee3c2ba87ec65a586cb3099d477badb31ef4d19|evidence=statement:argument="ALTERTABLE{$qualified}DROPCONSTRAINT{$probe}"=1
TrainingBenchmarkOnlineMigrationRuntime|restoreSessionTimeouts|237ec466db47a7ba0f914c7c23b2a6ecdfdd08a1a55dcc5fe127d35ba9c33b71|evidence=select:sql=SELECT set_config('lock_timeout', ?, false), set_config('statement_timeout', ?, false)=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|90d661171176e4414c33184f104e21e72359dde09a8fc612721c1980c087aff6|evidence=statement:argument="ALTERTABLE{$schema}.{$table}DROPCONSTRAINTIFEXISTS{$finalName}"=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|90d661171176e4414c33184f104e21e72359dde09a8fc612721c1980c087aff6|evidence=statement:argument="ALTERTABLE{$schema}.{$table}RENAMECONSTRAINT{$temporaryName}TO{$finalName}"=1
TrainingBenchmarkOnlineMigrationRuntime|swapValidatedConstraint|90d661171176e4414c33184f104e21e72359dde09a8fc612721c1980c087aff6|evidence=statement:argument="LOCKTABLE{$schema}.{$table}INACCESSEXCLUSIVEMODE"=1
TrainingBenchmarkOnlineMigrationRuntime|validateConstraint|e4c9998cd0c52ddae4ee6cf5acfe8db66e9b9f7f41137c833269f9398855f70a|evidence=statement:argument="ALTERTABLE{$schema}.{$table}VALIDATECONSTRAINT{$name}"=1
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
            if (str_contains(strtolower($relative), '/migrations/')) {
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

    public function test_structural_manifest_uses_explicit_machine_checked_evidence(): void
    {
        $source = file_get_contents(__FILE__);

        self::assertIsString($source);
        self::assertStringContainsString('|evidence=', $source);
        self::assertStringContainsString('$finding[\'evidence\']', $source);
    }
}
