<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\LegalBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ExecutiveBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\HandoverBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ChangeBusinessRagSource;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class AssistantLegalBusinessMetadata
{
    public static function records(): array
    {
        $p = static fn (string $type, bool $nullable = false): array => ['type' => $type, 'nullable' => $nullable];
        $doc = ['document_id' => $p('legal_document')];
        $version = $doc + ['document_version_id' => $p('legal_document_version', true) + ['matches' => ['document_id' => 'document_id']]];
        return [
            'legal_document' => ['LegalArchive/LegalArchiveDocument', 'legal_business', ['id','primary_project_id','title','document_number','document_type','status','lifecycle_status','approval_status','signature_status','direction','document_date','effective_from','effective_until','archived_at','activated_at','completed_at','terminated_at'], [], []],
            'legal_document_file' => ['LegalArchive/LegalArchiveDocumentFile','legal_business',['id','document_id','role','title','sort_order','is_required'], $doc, ['legal_archive.files.view']],
            'legal_document_link' => ['LegalArchive/LegalArchiveDocumentLink','legal_business',['id','document_id','link_type'], $doc, []],
            'legal_document_profile' => ['LegalArchive/LegalArchiveDocumentTypeProfile','legal_business',['id','code','base_code','name','requires_signature','confidentiality_level','is_active','lock_version'], [], []],
            'legal_document_version' => ['LegalArchive/LegalArchiveDocumentVersion','legal_business',['id','document_id','document_file_id','version_number','version_label','is_current','status','processing_status','original_filename','mime_type','size_bytes','uploaded_at'], $doc + ['document_file_id' => $p('legal_document_file', true) + ['matches' => ['document_id' => 'document_id']]], ['legal_archive.files.view']],
            'legal_document_comment' => ['LegalArchive/LegalDocumentComment','legal_business',['id','document_id','document_version_id','body','page_number','visibility','is_blocking','status','resolution','resolved_at'], $version, []],
            'legal_document_obligation' => ['LegalArchive/LegalDocumentObligation','legal_business',['id','project_id','document_id','document_version_id','title','responsible_party','due_at','amount','volume','unit','status','completed_at'], $version, []],
            'legal_document_party' => ['LegalArchive/LegalDocumentParty','legal_business',['id','document_id','document_version_id','snapshot_set_id','party_role','legal_name','representative_position','data_source'], $version + ['snapshot_set_id' => $p('legal_party_snapshot', true) + ['matches' => ['document_id' => 'document_id']]], []],
            'legal_party_snapshot' => ['LegalArchive/LegalDocumentPartySnapshotSet','legal_business',['id','document_id','document_version_id','captured_at'], $version, []],
            'legal_document_signature' => ['LegalArchive/LegalDocumentSignature','legal_business',['id','document_id','document_version_id','signature_request_id','party_id','method','provider','signed_at','verified_at','verification_status','signature_kind','container_format','party_role_snapshot'], $version + ['signature_request_id' => $p('legal_signature_request', true), 'party_id' => $p('legal_document_party', true)], ['legal_archive.signatures.view','finance.view']],
            'legal_signature_request' => ['LegalArchive/LegalSignatureRequest','legal_business',['id','document_id','document_version_id','party_id','method','provider','status','requested_at','expires_at','completed_at'], $version + ['party_id' => $p('legal_document_party', true)], ['legal_archive.signatures.view','finance.view']],
            'legal_signature_verification' => ['LegalArchive/LegalSignatureVerification','legal_business',['id','document_id','document_version_id','signature_id','provider','status','verified_at'], $version + ['signature_id' => $p('legal_document_signature')], ['legal_archive.signatures.view','finance.view']],
            'legal_workflow_decision' => ['LegalArchive/LegalWorkflowDecision','legal_business',['id','instance_id','step_id','document_id','document_version_id','action','from_status','to_status','decided_at','assignment_revision'], $version + ['instance_id' => $p('legal_workflow_instance'), 'step_id' => $p('legal_workflow_step', true)], ['legal_archive.workflow.view','finance.view']],
            'legal_workflow_instance' => ['LegalArchive/LegalWorkflowInstance','legal_business',['id','document_id','document_version_id','template_id','template_version','status','submitted_at','due_at','completed_at','cancelled_at','expired_at'], $version + ['template_id' => $p('legal_workflow_template', true)], ['legal_archive.workflow.view','finance.view']],
            'legal_workflow_step' => ['LegalArchive/LegalWorkflowStep','legal_business',['id','instance_id','step_key','label','sequence','parallel_group','required','status','deadline_at','activated_at','due_at','completed_at','assignment_revision'], ['instance_id' => $p('legal_workflow_instance')], ['legal_archive.workflow.view','finance.view']],
            'legal_workflow_template' => ['LegalArchive/LegalWorkflowTemplate','legal_business',['id','code','version','name'], [], ['legal_archive.workflow.view']],
            'legal_workflow_template_step' => ['LegalArchive/LegalWorkflowTemplateStep','legal_business',['id','template_id','step_key','label','sequence','parallel_group','required','due_in_hours'], ['template_id' => $p('legal_workflow_template')], ['legal_archive.workflow.view']],
            'executive_approved_list' => ['ExecutiveDocumentation/ExecutiveDocumentApprovedList','executive_business',['id','project_id','revision','approved_by_party','approved_at','original_name'], [], []],
            'executive_import' => ['ExecutiveDocumentation/ExecutiveDocumentImport','executive_business',['id','document_set_id','created_at'], ['document_set_id' => $p('executive_document_set')], []],
            'executive_import_item' => ['ExecutiveDocumentation/ExecutiveDocumentImportItem','executive_business',['id','import_id','original_name','size','status','attempt','document_id'], ['import_id' => $p('executive_import'), 'document_id' => $p('executive_document', true)], []],
            'executive_legal_version' => ['ExecutiveDocumentation/ExecutiveDocumentLegalArchiveVersion','executive_business',['id','executive_document_version_id','legal_archive_document_version_id'], ['executive_document_version_id' => $p('executive_version'), 'legal_archive_document_version_id' => $p('legal_document_version')], ['legal_archive.view','legal_archive.files.view','finance.view']],
            'executive_relation' => ['ExecutiveDocumentation/ExecutiveDocumentRelation','executive_business',['id','document_id','relation_type'], ['document_id' => $p('executive_document')], []],
            'executive_remark' => ['ExecutiveDocumentation/ExecutiveDocumentRemark','executive_business',['id','document_id','version_id','body','severity','status','resolution_comment','response','review_comment','answered_at','reviewed_at','resolved_at'], ['document_id' => $p('executive_document'), 'version_id' => $p('executive_version', true) + ['matches' => ['document_id' => 'document_id']]], ['executive-documentation.review','finance.view']],
            'executive_requirement' => ['ExecutiveDocumentation/ExecutiveDocumentRequirement','executive_business',['id','project_id','document_set_id','work_type_id','project_location_id','completed_work_id','stage','requirement_key','title','profile_type','applicability','source_revision','revision','not_applicable_at','superseded_at','applicability_at'], ['document_set_id' => $p('executive_document_set', true), 'project_location_id' => $p('project_location', true), 'completed_work_id' => $p('completed_work', true)], []],
            'executive_transmittal' => ['ExecutiveDocumentation/ExecutiveDocumentTransmittal','executive_business',['id','document_set_id','transmittal_number','status','transmitted_at','acknowledged_at','decision_at'], ['document_set_id' => $p('executive_document_set')], ['finance.view']],
            'executive_version' => ['ExecutiveDocumentation/ExecutiveDocumentVersion','executive_business',['id','document_id','version_number','status','uploaded_at','submitted_at','approved_at','transmitted_at'], ['document_id' => $p('executive_document')], []],
            'acceptance_quantity' => ['HandoverAcceptance/AcceptanceScopeWorkQuantity','handover_business',['id','project_id','acceptance_scope_id','completed_work_id','unit_id','presented_quantity','accepted_quantity','defect_quantity','revision'], ['acceptance_scope_id' => $p('acceptance_scope'), 'completed_work_id' => $p('completed_work')], ['finance.view']],
            'work_rework' => ['HandoverAcceptance/WorkRework','handover_business',['id','project_id','acceptance_scope_id','quantity_line_id','quantity','unit_id','status','revision','submitted_at','verified_at'], ['acceptance_scope_id' => $p('acceptance_scope'), 'quantity_line_id' => $p('acceptance_quantity')], ['finance.view']],
            'handover_evidence_event' => ['HandoverAcceptance/Reporting/Readiness/HandoverEvidenceEvent','handover_business',['id','project_id','acceptance_scope_id','event_type','source_code','status','occurred_at'], ['acceptance_scope_id' => $p('acceptance_scope')], ['finance.view']],
            'handover_gate_version' => ['HandoverAcceptance/Reporting/Readiness/HandoverGateVersion','handover_business',['id','project_id','acceptance_scope_id','location_id','package_id','gate_code','gate_version','explicitly_empty_requirements','effective_from','effective_to'], ['acceptance_scope_id' => $p('acceptance_scope'), 'location_id' => $p('project_location', true), 'package_id' => $p('handover_package', true)], ['finance.view']],
            'handover_readiness_row' => ['HandoverAcceptance/Reporting/Readiness/HandoverReadinessRow','handover_business',['id','snapshot_id','project_id','acceptance_scope_id','location_id','package_id','gate_code','due_on','mandatory_completeness','document_completeness','open_hard_blocker_count','attempt_count','successful_result_count','ready'], ['snapshot_id' => $p('handover_readiness_snapshot'), 'acceptance_scope_id' => $p('acceptance_scope'), 'location_id' => $p('project_location', true), 'package_id' => $p('handover_package', true)], ['finance.view']],
            'handover_readiness_snapshot' => ['HandoverAcceptance/Reporting/Readiness/HandoverReadinessSnapshot','handover_business',['id','as_of','generated_at','stale_at','row_count'], [], ['finance.view']],
            'change_rfi_history' => ['ChangeManagement/ChangeManagementRfiHistory','change_business',['id','rfi_id','event','from_status','to_status','message','created_at'], ['rfi_id' => $p('change_management_rfi')], ['finance.view']],
            'change_history_checkpoint' => ['ChangeManagement/Reporting/ChangeClaim/ChangeClaimHistoryCheckpoint','change_business',['id','completed_at','change_request_count','version_count','workflow_event_count','claim_link_count','ledger_count','unprojectable_legacy_count'], [], ['finance.view']],
            'change_claim_link' => ['ChangeManagement/Reporting/ChangeClaim/ChangeClaimLink','change_business',['id','change_request_version_id','change_claim_id','claim_version','claim_amount_minor','currency','relationship_type'], ['change_request_version_id' => $p('change_request_version'), 'change_claim_id' => $p('change_claim')], ['finance.view']],
            'change_claim_row' => ['ChangeManagement/Reporting/ChangeClaim/ChangeClaimRow','change_business',['id','snapshot_id','project_id','contract_id','contract_project_allocation_id','change_request_id','change_version','status','occurred_on','currency','proposed_exposure_minor','approved_exposure_minor','linked_claim_minor','opening_contingency_minor','allocated_contingency_minor','consumed_contingency_minor','released_contingency_minor','closing_contingency_minor','quality_status'], ['snapshot_id' => $p('change_claim_snapshot'), 'change_request_id' => $p('change_request', true), 'contract_id' => $p('contract', true)], ['finance.view']],
            'change_claim_snapshot' => ['ChangeManagement/Reporting/ChangeClaim/ChangeClaimSnapshot','change_business',['id','formula_version','as_of','generated_at','stale_at','row_count','coverage_numerator','coverage_denominator','quality_status'], [], ['finance.view']],
            'change_request_version' => ['ChangeManagement/Reporting/ChangeClaim/ChangeRequestVersion','change_business',['id','project_id','change_request_id','version','contract_id','contract_project_allocation_id','initiator_type','status','proposed_cost_minor','proposed_schedule_days','approved_cost_minor','approved_schedule_days','currency','effective_at'], ['change_request_id' => $p('change_request'), 'contract_id' => $p('contract', true)], ['finance.view']],
            'change_workflow_event' => ['ChangeManagement/Reporting/ChangeClaim/ChangeWorkflowEvent','change_business',['id','project_id','change_request_id','version','event_type','prior_status','current_status','occurred_at'], ['change_request_id' => $p('change_request')], ['finance.view']],
            'contingency_ledger' => ['ChangeManagement/Reporting/ChangeClaim/ContingencyLedgerEntry','change_business',['id','project_id','contract_project_allocation_id','currency','movement_type','signed_amount_minor','effective_on','effective_at'], [], ['finance.view']],
        ];
    }

    public static function entityDefinitions(): array
    {
        $result = [];
        foreach (self::records() as $type => [$path, $source]) {
            $parts = explode('/', $path); $class = array_pop($parts);
            $result[$type] = [$source, 'App\\BusinessModules\\Features\\'.implode('\\', $parts).'\\Models\\'.$class, $source];
        }
        return $result;
    }
    public static function domainGates(): array
    {
        return ['legal_business' => ['file-management',['legal_archive.view']], 'executive_business' => ['executive-documentation',['executive-documentation.view']],
            'handover_business' => ['handover-acceptance',['handover-acceptance.view']], 'change_business' => ['change-management',['change-management.view']]];
    }
    public static function fields(): array { return array_map(static fn (array $record): array => $record[2], self::records()); }
    public static function parentColumns(): array
    {
        $result = [];
        foreach (self::records() as $type => $record) {
            $result[$type] = $record[3];
            foreach ($result[$type] as $column => &$parent) {
                $parentFields = self::fields()[$parent['type']] ?? [];
                foreach (['document_id','document_version_id','instance_id','acceptance_scope_id','project_id','completed_work_id','document_set_id'] as $scope) {
                    if (in_array($scope,$record[2],true) && in_array($scope,$parentFields,true)) { $parent['matches'][$scope] = $scope; }
                }
            }
            unset($parent);
        }
        return $result;
    }
    public static function entityPermissions(): array { return array_map(static fn (array $record): array => $record[4], self::records()); }
    public static function sourcePermissions(): array { return array_fill_keys(array_keys(self::domainGates()), ['finance.view']); }
    public static function organizationColumns(): array { return ['executive_import_item' => null, 'change_rfi_history' => null]; }
    public static function organizationAggregates(): array { return array_fill_keys(['change_history_checkpoint','change_claim_snapshot','handover_readiness_snapshot'], true); }
    public static function versionColumns(): array
    {
        $result = array_fill_keys(array_keys(self::records()), ['updated_at']);
        $result['handover_evidence_event'] = ['created_at']; $result['handover_readiness_row'] = [];
        $result['handover_readiness_snapshot'] = ['generated_at'];
        return $result;
    }
    public static function safeSelectColumns(): array
    {
        $result = [];
        foreach (self::records() as $type => $record) {
            $result[$type] = array_values(array_unique([...$record[2], ...array_keys($record[3]), ...self::versionColumns()[$type],
                ...(array_key_exists($type, self::organizationColumns()) ? [] : ['organization_id'])]));
        }
        return $result;
    }
    public static function sourceClasses(): array { return [LegalBusinessRagSource::class,ExecutiveBusinessRagSource::class,HandoverBusinessRagSource::class,ChangeBusinessRagSource::class]; }
    public static function observerDefinitions(): array
    {
        $result = [];
        foreach (self::entityDefinitions() as $type => [$source,$class]) { $result[$class] = [$source,$type]; }
        return $result;
    }
    public static function actorScopeHelpers(): array { return array_fill_keys(array_keys(self::records()), self::class); }
    public static function navigationTemplates(): array { return array_fill_keys(array_keys(self::records()), ''); }
    public static function structuredFields(): array { return array_values(array_unique(array_merge(...array_values(self::fields())))); }
    public static function excludedModels(): array
    {
        return ['LegalDocumentAccessGrant' => 'Технические права доступа; используется для ACL, не является содержимым документа.',
            'LegalDocumentEditorParticipant' => 'Идентификаторы участников и провайдера редактора.', 'LegalDocumentEditorSave' => 'Callback/replay/lease состояния сохранения.',
            'LegalDocumentEditorSession' => 'Ключи документа и сессии редактора.', 'LegalDocumentNotificationDelivery' => 'Техническая доставка и персональные получатели.',
            'LegalDocumentOutboxMessage' => 'Outbox payload и служебные токены.', 'LegalSignatureProviderOperation' => 'Provider request/redirect/session/lease секреты.'];
    }
    public static function technicalFields(): array
    {
        return ['file_path','file_url','url','external_url','staged_path','structured_fields','metadata','profile_snapshot','basis_snapshot','manifest','items',
            'evidence','evidence_snapshot','financial_impact','signers','signature_path','certificate_metadata','provider_metadata','session_metadata','bank_details',
            'tax_number','registration_number','legal_address','representative_name','source_create_attempt_token','request_hash','idempotency_key','payload_hash',
            'rule_snapshot','coverage_scope','scope_identity','filters','watermarks','totals','warnings','snapshot','context','settings'];
    }
    public static function moneyFields(): array
    {
        return ['amount','claim_amount_minor','proposed_exposure_minor','approved_exposure_minor','linked_claim_minor','opening_contingency_minor','allocated_contingency_minor',
            'consumed_contingency_minor','released_contingency_minor','closing_contingency_minor','proposed_cost_minor','approved_cost_minor','signed_amount_minor'];
    }

    public static function applyActorScope(string $type, Builder $query, User $actor, int $organizationId, AuthorizationService $authorization, AssistantDataAccessPolicy $policy): void
    {
        $table = $query->getModel()->getTable();
        if ($type === 'legal_document') {
            (new LegalDocumentAccessService($authorization->forCurrentChecks()))->scopeAccessibleQuery($query, $actor, $organizationId, 'view');
            $projects = $policy->entityQuery($actor,$organizationId,'project');
            $query->where(static function (Builder $project) use ($projects,$table): void {
                $project->whereNull($table.'.primary_project_id');
                if ($projects !== null) { $project->orWhereIn($table.'.primary_project_id',$projects->select('projects.id')); }
            });
            self::polymorphicScope($query, $table.'.source_type', $table.'.source_id', self::legalSourceTypes(), $actor, $organizationId, $policy, true);
        }
        if (in_array($type,['change_claim_snapshot','handover_readiness_snapshot'],true)) {
            self::aggregateScope($query,$type === 'change_claim_snapshot' ? 'change_claim_row' : 'handover_readiness_row',$actor,$organizationId,$authorization,$policy);
        }
        if ($type === 'legal_document_comment') {
            $query->where(static function (Builder $audience) use ($actor, $organizationId, $authorization, $table): void {
                $audience->whereIn($table.'.visibility', ['internal','all_parties'])
                    ->orWhere(static fn (Builder $author): Builder => $author->where($table.'.visibility', 'author_and_responsible')->where($table.'.author_user_id', $actor->id))
                    ->orWhereExists(static function (QueryBuilder $responsible) use ($table,$actor): void {
                        $responsible->selectRaw('1')->from('legal_archive_documents')->whereColumn('legal_archive_documents.id', $table.'.document_id')
                            ->where('legal_archive_documents.responsible_user_id', $actor->id)->where($table.'.visibility', 'author_and_responsible');
                    });
                if ($authorization->canCurrent($actor, 'legal_archive.workflow.approve', ['organization_id' => $organizationId])) {
                    $documents = (new LegalDocumentAccessService($authorization->forCurrentChecks()))->scopeAccessibleQuery(
                        \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument::query(), $actor, $organizationId, 'approve');
                    $audience->orWhere(static fn (Builder $blocking): Builder => $blocking->where($table.'.is_blocking', true)
                        ->whereIn($table.'.document_id', $documents->select('legal_archive_documents.id')));
                }
            });
        }
        if (in_array($type, ['legal_document_link','executive_relation','handover_evidence_event','contingency_ledger'], true)) {
            [$typeColumn,$idColumn,$types] = match ($type) {
                'legal_document_link' => ['linked_type','linked_id',self::legalSourceTypes()],
                'executive_relation' => ['target_type','target_id',self::legalSourceTypes() + self::evidenceTypes()],
                default => ['source_type','source_id',self::evidenceTypes() + self::legalSourceTypes()],
            };
            self::polymorphicScope($query,$table.'.'.$typeColumn,$table.'.'.$idColumn,$types,$actor,$organizationId,$policy,false);
        }
        if (in_array($type,['change_claim_row','handover_readiness_row'],true)) {
            self::referenceScope($query,$type === 'change_claim_row' ? 'source_refs' : 'evidence_refs',
                $type === 'change_claim_row' ? 'type' : 'source_type',$type === 'change_claim_row' ? 'id' : 'source_id',
                $actor,$organizationId,$policy);
        }
        if (in_array($type,['contingency_ledger','change_claim_row','change_request_version'],true)) {
            $contracts = $policy->entityQuery($actor,$organizationId,'contract');
            $projects = $policy->entityQuery($actor,$organizationId,'project');
            if ($contracts === null || $projects === null) { $query->whereRaw('1 = 0'); return; }
            $query->where(static function (Builder $allocated) use ($type,$table,$contracts,$projects): void {
                if ($type === 'change_request_version') { $allocated->whereNull($table.'.contract_project_allocation_id'); }
                $method = $type === 'change_request_version' ? 'orWhereExists' : 'whereExists';
                $allocated->{$method}(static function (QueryBuilder $allocation) use ($type,$table,$contracts,$projects): void {
                    $allocation->selectRaw('1')->from('contract_project_allocations')->whereColumn('contract_project_allocations.id',$table.'.contract_project_allocation_id')
                        ->where('contract_project_allocations.is_active',true)->whereNull('contract_project_allocations.deleted_at')
                        ->whereColumn('contract_project_allocations.project_id',$table.'.project_id')->whereIn('contract_project_allocations.project_id',$projects->select('projects.id'))
                        ->whereIn('contract_project_allocations.contract_id',$contracts->select('contracts.id'));
                    if ($type !== 'contingency_ledger') { $allocation->whereColumn('contract_project_allocations.contract_id',$table.'.contract_id'); }
                });
            });
        }
    }

    public static function legalSourceTypes(): array
    {
        return ['project'=>'project','contract'=>'contract','supplementary_agreement'=>'supplementary_agreement','performance_act'=>'performance_act','purchase_order'=>'purchase_order','payment_document'=>'payment_document',
            'commercial_proposal'=>'commercial_proposal','crm_deal'=>'crm_deal','estimate'=>'estimate','executive_document'=>'executive_document'];
    }
    public static function evidenceTypes(): array
    {
        return ['rfi'=>'change_management_rfi','change'=>'change_request','change_request'=>'change_request','change_claim'=>'change_claim','quality_defect'=>'quality_defect','constraint'=>'work_constraint',
            'inspection'=>'acceptance_scope','acceptance_scope'=>'acceptance_scope','acceptance_checklist_item'=>'acceptance_checklist_item','acceptance_finding'=>'acceptance_finding',
            'handover_document'=>'handover_package_document','document'=>'handover_package_document'];
    }
    private static function polymorphicScope(Builder $query,string $typeColumn,string $idColumn,array $types,User $actor,int $organizationId,AssistantDataAccessPolicy $policy,bool $manual): void
    {
        $query->where(static function (Builder $scope) use ($typeColumn,$idColumn,$types,$actor,$organizationId,$policy,$manual): void {
            $scope->whereRaw('1 = 0');
            if ($manual) { $scope->orWhere(static fn (Builder $empty): Builder => $empty->whereNull($typeColumn)->whereNull($idColumn)); }
            foreach ($types as $source => $entityType) {
                $parent = self::sourceQuery($actor,$organizationId,$entityType,$policy);
                if ($parent === null) { continue; }
                $parentTable = $parent->getModel()->getTable();
                $scope->orWhere(static fn (Builder $linked): Builder => $linked->where($typeColumn,$source)
                    ->whereIn(\Illuminate\Support\Facades\DB::raw('CAST('.$idColumn.' AS TEXT)'),$parent->select([])->selectRaw('CAST('.$parentTable.'.id AS TEXT)')));
            }
        });
    }
    private static function referenceScope(Builder $query,string $column,string $typeKey,string $idKey,User $actor,int $organizationId,AssistantDataAccessPolicy $policy): void
    {
        $table = $query->getModel()->getTable();
        $query->whereRaw('jsonb_typeof('.$table.'.'.$column.") = 'array'");
        $query->whereNotExists(static function (QueryBuilder $refs) use ($table,$column,$typeKey,$idKey,$actor,$organizationId,$policy): void {
            $refs->selectRaw('1')->fromRaw('jsonb_array_elements(CASE WHEN jsonb_typeof('.$table.'.'.$column.") = 'array' THEN ".$table.'.'.$column." ELSE '[]'::jsonb END) as assistant_business_ref(value)")
                ->whereNot(static function (QueryBuilder $allowed) use ($typeKey,$idKey,$actor,$organizationId,$policy): void {
                    $allowed->whereRaw('1 = 0');
                    foreach (self::evidenceTypes()+self::legalSourceTypes() as $source => $entityType) {
                        $parent = self::sourceQuery($actor,$organizationId,$entityType,$policy);
                        if ($parent === null) { continue; }
                        $parentTable = $parent->getModel()->getTable();
                        $allowed->orWhere(static fn (QueryBuilder $linked): QueryBuilder => $linked->whereRaw("COALESCE(assistant_business_ref.value->>'".$typeKey."', '') = ?",[$source])
                            ->whereIn(\Illuminate\Support\Facades\DB::raw("COALESCE(assistant_business_ref.value->>'".$idKey."', '')"),$parent->select([])->selectRaw('CAST('.$parentTable.'.id AS TEXT)')));
                    }
                });
        });
    }
    private static function sourceQuery(User $actor,int $organizationId,string $entityType,AssistantDataAccessPolicy $policy): ?Builder
    {
        if ($entityType === 'estimate' && ! $policy->canCurrentPermission($actor,$organizationId,'budget-estimates.finance.view')) { return null; }
        if ($entityType === 'supplementary_agreement') {
            $contracts = $policy->entityQuery($actor,$organizationId,'contract');
            return $contracts === null ? null : \App\Models\SupplementaryAgreement::query()->whereIn('contract_id',$contracts->select('contracts.id'));
        }
        if ($entityType === 'work_constraint') {
            $projects = $policy->entityQuery($actor,$organizationId,'project');
            if ($projects === null || ! $policy->canReadDomain($actor,$organizationId,'schedule')) { return null; }
            return \App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint::query()->where('organization_id',$organizationId)->whereIn('project_id',$projects->select('projects.id'));
        }
        return $policy->entityQuery($actor,$organizationId,$entityType);
    }
    private static function aggregateScope(Builder $query,string $rowType,User $actor,int $organizationId,AuthorizationService $authorization,AssistantDataAccessPolicy $policy): void
    {
        $class = self::entityDefinitions()[$rowType][1]; $rows = $class::query()->where('organization_id',$organizationId);
        $rowTable = $rows->getModel()->getTable(); $table = $query->getModel()->getTable();
        $projects = $policy->entityQuery($actor,$organizationId,'project');
        if ($projects === null) { $query->whereRaw('1 = 0'); return; }
        $rows->whereIn($rowTable.'.project_id',$projects->select('projects.id'));
        self::applyActorScope($rowType,$rows,$actor,$organizationId,$authorization,$policy);
        foreach (self::parentColumns()[$rowType] as $column => $parent) {
            if ($column === 'snapshot_id') { continue; }
            $parentQuery = $policy->entityQuery($actor,$organizationId,$parent['type']);
            if ($parentQuery !== null) {
                $parentTable = $parentQuery->getModel()->getTable();
                foreach ($parent['matches'] ?? [] as $parentColumn=>$childColumn) { $parentQuery->whereColumn($parentTable.'.'.$parentColumn,$rowTable.'.'.$childColumn); }
                if (in_array('project_id',$parentQuery->getModel()->getFillable(),true)) { $parentQuery->whereRaw($parentTable.'.project_id IS NOT DISTINCT FROM '.$rowTable.'.project_id'); }
            }
            $rows->where(static function (Builder $scope) use ($parentQuery,$column,$parent,$rowTable): void {
                $scope->whereRaw('1 = 0');
                if ($parent['nullable']) { $scope->orWhereNull($rowTable.'.'.$column); }
                if ($parentQuery !== null) { $parentTable = $parentQuery->getModel()->getTable(); $scope->orWhereIn($rowTable.'.'.$column,$parentQuery->select($parentTable.'.id')); }
            });
        }
        $query->whereNotExists(static function (QueryBuilder $unreadable) use ($rowTable,$table,$rows): void {
            $unreadable->selectRaw('1')->from($rowTable)->whereColumn($rowTable.'.snapshot_id',$table.'.id')->whereNotIn($rowTable.'.id',$rows->select($rowTable.'.id'));
        });
    }
    public static function entityLabels(): array
    {
        return array_combine(array_keys(self::records()), ['Юридический документ','Файл юридического документа','Связь юридического документа','Профиль юридического документа','Версия юридического документа',
            'Комментарий к юридическому документу','Обязательство по документу','Сторона документа','Состав сторон документа','Подпись документа','Запрос подписи','Проверка подписи',
            'Решение согласования','Процесс согласования','Шаг согласования','Шаблон согласования','Шаг шаблона согласования','Утверждённый перечень ИД','Импорт ИД','Строка импорта ИД',
            'Связь версии ИД с архивом','Связь исполнительного документа','Замечание по ИД','Требование к ИД','Передача ИД','Версия ИД','Объём приёмки','Переделка работы',
            'Событие готовности приёмки','Версия условий приёмки','Строка готовности приёмки','Снимок готовности приёмки','История RFI','Контрольная точка истории изменений',
            'Связь изменения с претензией','Строка экспозиции изменений','Снимок экспозиции изменений','Версия изменения','Событие согласования изменения','Движение резерва']);
    }
    public static function fieldLabels(): array
    {
        return ['lifecycle_status'=>'Состояние документа','approval_status'=>'Согласование','signature_status'=>'Подписание','document_number'=>'Номер документа','document_type'=>'Тип документа',
            'verification_status'=>'Проверка подписи','signature_kind'=>'Вид подписи','container_format'=>'Формат подписи','is_blocking'=>'Блокирующее замечание','volume'=>'Объём','unit'=>'Единица',
            'presented_quantity'=>'Предъявленный объём','accepted_quantity'=>'Принятый объём','defect_quantity'=>'Дефектный объём','mandatory_completeness'=>'Готовность обязательных требований',
            'document_completeness'=>'Готовность документов','open_hard_blocker_count'=>'Открытых блокирующих замечаний','attempt_count'=>'Попыток приёмки','successful_result_count'=>'Успешных результатов',
            'ready'=>'Готовность','claim_amount_minor'=>'Сумма претензии, коп.','proposed_exposure_minor'=>'Предлагаемая экспозиция, коп.','approved_exposure_minor'=>'Утверждённая экспозиция, коп.',
            'linked_claim_minor'=>'Связанные претензии, коп.','opening_contingency_minor'=>'Входящий резерв, коп.','allocated_contingency_minor'=>'Выделенный резерв, коп.',
            'consumed_contingency_minor'=>'Использованный резерв, коп.','released_contingency_minor'=>'Освобождённый резерв, коп.','closing_contingency_minor'=>'Исходящий резерв, коп.',
            'proposed_cost_minor'=>'Предлагаемая стоимость, коп.','approved_cost_minor'=>'Утверждённая стоимость, коп.','signed_amount_minor'=>'Изменение резерва, коп.',
            'generated_at'=>'Сформирован','as_of'=>'Данные на','stale_at'=>'Устаревает','row_count'=>'Количество строк','due_at'=>'Срок исполнения'];
    }
    public static function factFieldGroups(): array
    {
        return ['status'=>['status','lifecycle_status','approval_status','signature_status','verification_status','from_status','to_status','current_status','prior_status'],
            'date'=>['document_date','effective_from','effective_until','archived_at','activated_at','completed_at','terminated_at','uploaded_at','due_at','signed_at','verified_at','submitted_at','resolved_at','decided_at','expires_at','as_of','generated_at','stale_at','occurred_at'],
            'quantity'=>['volume','quantity','presented_quantity','accepted_quantity','defect_quantity','row_count','open_hard_blocker_count','attempt_count','successful_result_count']];
    }
    public static function domainDefinitions(): array
    {
        $result = [];
        foreach (self::domainGates() as $domain => [$module,$permissions]) {
            $types = array_keys(array_filter(self::records(), static fn (array $record): bool => $record[1] === $domain));
            $fields = array_values(array_unique(array_merge(...array_map(static fn (string $type): array => self::fields()[$type], $types))));
            $entity = ['type' => 'string','enum' => $types]; $id = ['type' => ['integer','string'],'maxLength' => 64];
            $fieldSchema = ['type' => ['array','null'],'items' => ['type' => 'string','enum' => $fields]];
            $schema = static fn (array $properties): array => ['type' => 'object','properties' => $properties,'required' => array_keys($properties),'additionalProperties' => false];
            $schemas = ['search' => $schema(['entity_type' => $entity,'query' => ['type' => 'string','maxLength' => 200],'project_id' => ['type' => ['integer','null']],
                'limit' => ['type' => 'integer','minimum' => 1,'maximum' => 20],'fields' => $fieldSchema]),
                'read' => $schema(['entity_type' => $entity,'id' => $id,'fields' => $fieldSchema]),'navigation' => $schema(['entity_type' => $entity,'id' => $id])];
            $fieldPermissions = array_fill_keys(array_intersect($fields, [...self::moneyFields(),'signature_status','approval_status','verification_status','body','resolution','response','review_comment','resolution_comment','message']), 'finance.view');
            $result[] = new AssistantDomainDefinition($domain,$module,$types[0],$permissions,$fields,$schemas,array_keys($schemas),'',$domain,$types,
                $fieldPermissions,array_intersect_key(self::entityPermissions(),array_flip($types)));
        }
        return $result;
    }
}
