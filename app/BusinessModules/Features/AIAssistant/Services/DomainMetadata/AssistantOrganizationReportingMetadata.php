<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OrganizationReportingRagSource;

final class AssistantOrganizationReportingMetadata
{
    public static function records(): array
    {
        $mdm = 'App\\BusinessModules\\Core\\Mdm\\Models\\';
        $holding = 'App\\BusinessModules\\Core\\MultiOrganization\\Reporting\\Models\\';
        $site = 'App\\BusinessModules\\Enterprise\\MultiOrganization\\Website\\Domain\\Models\\';
        $report = 'App\\BusinessModules\\Core\\Reporting\\Infrastructure\\Persistence\\Models\\';
        $estimate = 'App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\';
        $normative = 'App\\BusinessModules\\Addons\\EstimateGeneration\\Normatives\\Models\\';
        return [
            'mdm_record' => [$mdm.'MdmRecord', ['id','organization_id','display_name','status','quality_score','version','last_synced_at'], 'mdm', 'Состояние основных данных'],
            'mdm_change_request' => [$mdm.'MdmChangeRequest', ['id','organization_id','mdm_record_id','action','status','priority','requested_by_user_id','submitted_at','approved_at','applied_at'], 'mdm_requests', 'Запрос изменения основных данных'],
            'mdm_change_event' => [$mdm.'MdmChangeRequestEvent', ['id','organization_id','change_request_id','event_type','before_status','after_status','created_at'], 'mdm_requests', 'Событие изменения основных данных'],
            'mdm_change_log' => [$mdm.'MdmChangeLog', ['id','organization_id','mdm_record_id','action','created_at'], 'mdm', 'История основных данных'],
            'mdm_duplicate_group' => [$mdm.'MdmDuplicateGroup', ['id','organization_id','status','confidence','resolved_at'], 'mdm', 'Группа дубликатов'],
            'mdm_duplicate_member' => [$mdm.'MdmDuplicateMember', ['id','duplicate_group_id','role','score'], 'mdm', 'Участник группы дубликатов'],
            'mdm_import_batch' => [$mdm.'MdmImportBatch', ['id','organization_id','status','total_rows','accepted_rows','rejected_rows','created_by_user_id'], 'mdm', 'Импорт основных данных'],
            'mdm_merge_run' => [$mdm.'MdmMergeRun', ['id','organization_id','duplicate_group_id','status','applied_at'], 'mdm', 'Объединение дубликатов'],
            'mdm_quality_policy' => [$mdm.'MdmQualityPolicy', ['id','organization_id','entity_type','min_acceptable_score'], 'mdm', 'Правила качества данных'],
            'organization_group_card' => [\App\Models\OrganizationGroup::class, ['id','name','slug','status','parent_organization_id'], 'holding_site', 'Группа организаций'],
            'holding_site_card' => [$site.'HoldingSite', ['id','organization_group_id','title','description','status','is_active','published_at'], 'holding_site', 'Сайт группы организаций'],
            'holding_site_page' => [$site.'HoldingSitePage', ['id','holding_site_id','page_type','slug','navigation_label','title','description','visibility','is_active'], 'holding_site', 'Страница сайта'],
            'holding_site_block' => [$site.'SiteContentBlock', ['id','holding_site_id','holding_site_page_id','block_type','title','status','is_active'], 'holding_site', 'Блок сайта'],
            'holding_site_revision' => [$site.'HoldingSiteRevision', ['id','holding_site_id','kind','label','created_by_user_id','created_at'], 'holding_site', 'Редакция сайта'],
            'holding_site_asset' => [$site.'SiteAsset', ['id','holding_site_id','filename','mime_type','file_size','asset_type'], 'holding_site', 'Файл сайта'],
            'holding_site_lead' => [$site.'HoldingSiteLead', ['id','holding_site_id','holding_site_page_id','contact_name','company_name','status','submitted_at'], 'holding_site', 'Обращение с сайта'],
            'holding_site_collaborator' => [$site.'HoldingSiteCollaborator', ['id','holding_site_id','user_id','role'], 'holding_site', 'Редактор сайта'],
            'holding_site_template' => [$site.'SiteTemplate', ['id','template_key','name','description','is_active','is_premium','version'], 'holding_templates', 'Шаблон сайта'],
            'holding_contract_evidence' => [$holding.'HoldingContractVersionEvidence', ['id','organization_id','contract_id','total_amount','recorded_at'], 'holding_finance', 'Версия договора'],
            'holding_project_allocation_card' => [\App\Models\ContractProjectAllocation::class, ['id','contract_id','project_id','is_active'], 'holding_finance', 'Распределение договора по проекту'],
            'holding_work_event' => [$holding.'HoldingAcceptedWorkEventVersion', ['id','organization_id','project_id','contract_id','performance_act_id','amount','status','active','occurred_at','recorded_at'], 'holding_finance', 'Событие принятия работ'],
            'holding_payment_event' => [$holding.'HoldingPaymentTransactionEventVersion', ['id','organization_id','project_id','contract_id','payment_document_id','amount','currency','status','active','occurred_at','recorded_at'], 'holding_finance', 'Событие оплаты'],
            'holding_allocation_fact' => [$holding.'HoldingAllocationFactVersion', ['id','organization_id','contributor_organization_id','project_id','contract_id','amount_minor','currency','recognized_on','recorded_at'], 'holding_finance', 'Распределение суммы'],
            'holding_performance_snapshot' => [$holding.'HoldingPerformanceSnapshot', ['id','organization_id','quality_status','freshness_status','generated_at','stale_at'], 'holding_reports', 'Готовность отчёта холдинга'],
            'holding_performance_row' => [$holding.'HoldingPerformanceRow', ['id','organization_id','snapshot_id','contributor_organization_id','project_id','currency','period_start','contracted_minor','accepted_accrual_minor','cash_minor'], 'holding_finance', 'Показатели текущей организации'],
            'intercompany_flow_snapshot' => [$holding.'IntercompanyContractFlowSnapshot', ['id','organization_id','quality_status','freshness_status','generated_at','stale_at'], 'holding_reports', 'Готовность отчёта потоков'],
            'intercompany_flow_row' => [$holding.'IntercompanyContractFlowRow', ['id','organization_id','snapshot_id','project_id','allocation_id','currency','period_start','internal_minor','external_minor','unclassified_minor','total_minor'], 'holding_finance', 'Потоки текущей организации'],
            'report_run_card' => [$report.'ReportRunRecord', ['id','organization_id','requester_actor_id','report_code','status','progress','queued_at','ready_at','expires_at'], 'report_cards', 'Подготовка отчёта'],
            'report_export_card' => [$report.'ReportExportRecord', ['id','organization_id','requester_actor_id','run_id','format','status','queued_at','ready_at','expires_at'], 'report_cards', 'Экспорт отчёта'],
            'report_saved_view_card' => [$report.'ReportSavedViewRecord', ['id','organization_id','owner_id','report_code','name','visibility','status','is_default'], 'report_cards', 'Сохранённый отчёт'],
            'report_saved_view_revision' => [$report.'ReportSavedViewVersionRecord', ['id','organization_id','owner_id','saved_view_id','revision','report_code','created_at'], 'report_cards', 'Редакция сохранённого отчёта'],
            'report_subscription_card' => [$report.'ReportSubscriptionRecord', ['id','organization_id','owner_id','saved_view_id','report_code','frequency','format','status','next_run_at'], 'report_cards', 'Расписание отчёта'],
            'report_delivery_card' => [$report.'ReportSubscriptionDeliveryRecord', ['id','organization_id','owner_id','subscription_id','run_id','export_id','status','scheduled_for','notified_at'], 'report_cards', 'Доставка отчёта'],
            'estimate_generation_session_card' => [$estimate.'EstimateGenerationSession', ['id','organization_id','project_id','user_id','status','processing_stage','processing_progress','state_version'], 'generation_cards', 'Подготовка сметы'],
            'estimate_generation_document_card' => [$estimate.'EstimateGenerationDocument', ['id','organization_id','project_id','user_id','session_id','filename','mime_type','status','processing_stage','progress_percent','page_count','processed_page_count'], 'generation_cards', 'Документ для сметы'],
            'estimate_generation_page_card' => [$estimate.'EstimateGenerationDocumentPage', ['id','organization_id','project_id','session_id','document_id','page_number','status','confidence'], 'generation_cards', 'Страница документа'],
            'estimate_generation_fact_card' => [$estimate.'EstimateGenerationDocumentFact', ['id','organization_id','project_id','session_id','document_id','page_id','fact_type','label','value_text','value_number','unit','confidence'], 'generation_finance', 'Факт документа'],
            'estimate_generation_element_card' => [$estimate.'EstimateGenerationDrawingElement', ['id','organization_id','project_id','session_id','document_id','page_id','type','label','value_text','value_number','unit','confidence'], 'generation_finance', 'Элемент чертежа'],
            'estimate_generation_takeoff_card' => [$estimate.'EstimateGenerationQuantityTakeoff', ['id','organization_id','project_id','session_id','document_id','page_id','name','unit','quantity','confidence'], 'generation_cards', 'Объём работ'],
            'estimate_generation_scope_card' => [$estimate.'EstimateGenerationScopeInference', ['id','organization_id','project_id','session_id','document_id','page_id','inference_type','title','description','confidence','review_required','accepted_at'], 'generation_finance', 'Предполагаемый состав работ'],
            'estimate_generation_package_card' => [$estimate.'EstimateGenerationPackage', ['id','session_id','title','scope_type','status','generation_stage','generation_progress','actual_items_count','started_at','finished_at'], 'generation_cards', 'Раздел создаваемой сметы'],
            'estimate_generation_item_card' => [$estimate.'EstimateGenerationPackageItem', ['id','package_id','name','unit','quantity','unit_price','direct_cost','overhead_cost','profit_cost','total_cost','revision'], 'generation_finance', 'Позиция создаваемой сметы'],
            'estimate_generation_feedback_card' => [$estimate.'EstimateGenerationFeedback', ['id','session_id','user_id','feedback_type','section_key','work_item_key'], 'generation_cards', 'Оценка создаваемой сметы'],
            'estimate_generation_evidence_card' => [$estimate.'EstimateGenerationEvidence', ['id','organization_id','project_id','session_id','type','source_type','confidence','invalidated_at'], 'generation_cards', 'Подтверждение данных сметы'],
            'approved_estimate_dataset' => [$normative.'EstimateDatasetVersion', ['id','source_type','version_key','status','finished_at'], 'normative_lookup', 'Опубликованная версия нормативов'],
            'approved_construction_resource' => [$normative.'ConstructionResource', ['id','dataset_version_id','ksr_code','name','unit','resource_type'], 'normative_lookup', 'Нормативный ресурс'],
            'approved_estimate_norm_collection' => [$normative.'EstimateNormCollection', ['id','dataset_version_id','code','name','norm_type'], 'normative_lookup', 'Сборник нормативов'],
            'approved_estimate_norm_section' => [$normative.'EstimateNormSection', ['id','collection_id','code','name','section_type','depth'], 'normative_lookup', 'Раздел нормативов'],
            'approved_estimate_norm' => [$normative.'EstimateNorm', ['id','collection_id','section_id','code','name','unit','valid_from','valid_to'], 'normative_lookup', 'Норматив работ'],
            'approved_estimate_norm_resource' => [$normative.'EstimateNormResource', ['id','estimate_norm_id','construction_resource_id','resource_code','resource_name','unit','quantity','resource_type'], 'normative_lookup', 'Ресурс нормы'],
            'estimate_price_region' => [$normative.'EstimateRegion', ['id','code','name','is_supported'], 'normative_lookup', 'Регион нормативных цен'],
            'estimate_price_zone' => [$normative.'EstimatePriceZone', ['id','estimate_region_id','name'], 'normative_lookup', 'Ценовая зона'],
            'estimate_price_period' => [$normative.'EstimatePricePeriod', ['id','name','year','quarter','starts_at','ends_at'], 'normative_lookup', 'Период нормативных цен'],
            'active_estimate_price_version' => [$normative.'EstimateRegionalPriceVersion', ['id','region_id','price_zone_id','period_id','version_key','status','activated_at'], 'normative_prices', 'Действующая версия цен'],
            'active_estimate_price_activation' => [$normative.'EstimateRegionalPriceActivation', ['id','region_id','price_zone_id','active_version_id','activated_at'], 'normative_prices', 'Применение версии цен'],
            'approved_estimate_resource_price' => [$normative.'EstimateResourcePrice', ['id','dataset_version_id','regional_price_version_id','construction_resource_id','resource_code','resource_name','unit','base_price','machine_salary_price','machine_price_without_salary','price_type'], 'normative_prices', 'Нормативная цена ресурса'],
        ];
    }

    public static function entityDefinitions(): array
    {
        return array_map(static fn (array $row): array => ['organization_reporting', $row[0], $row[2]], self::records());
    }
    public static function fields(): array { return array_map(static fn (array $row): array => $row[1], self::records()); }
    public static function domainGates(): array
    {
        return ['mdm' => ['catalog-management',['mdm.view']], 'mdm_requests' => ['catalog-management',['mdm.view','mdm.change_requests.view']],
            'holding_site' => ['multi-organization',['multi-organization.website.view']],
            'holding_templates' => ['multi-organization',['multi-organization.website.templates.access']],
            'holding_reports' => ['multi-organization',['multi-organization.reports.view']],
            'holding_finance' => ['multi-organization',['multi-organization.reports.financial']],
            'report_cards' => ['reports',['reports.view']], 'generation_cards' => ['ai-estimates',['estimate_generation.view']],
            'generation_finance' => ['ai-estimates',['estimate_generation.view']],
            'normative_lookup' => ['ai-estimates',['estimate_generation.select_normative']],
            'normative_prices' => ['ai-estimates',['estimate_generation.select_normative']]];
    }
    public static function entityPermissions(): array
    {
        $result = [];
        foreach (self::records() as $type => $row) {
            $result[$type] = self::domainGates()[$row[2]][1];
            if (in_array($row[2], ['holding_finance','generation_finance','normative_prices'], true)) { $result[$type][] = 'finance.view'; }
            if (in_array($row[2], ['generation_finance','normative_prices'],true)) { $result[$type][] = 'budget-estimates.finance.view'; }
        }
        return $result;
    }
    public static function sourceClasses(): array { return [OrganizationReportingRagSource::class]; }
    public static function sourcePermissions(): array { return []; }
    public static function organizationColumns(): array { return ['organization_group_card' => 'parent_organization_id']; }
    public static function globalCatalogEntities(): array
    {
        return array_fill_keys(['holding_site_template','approved_estimate_dataset','estimate_price_region','estimate_price_period',
            'active_estimate_price_version','approved_estimate_resource_price'],true);
    }
    public static function globalFanoutEntities(): array
    {
        return array_fill_keys(array_keys(array_filter(self::records(),static fn (array $row): bool => in_array($row[2],['normative_lookup','normative_prices','holding_templates'],true))),true);
    }
    public static function rowPredicates(): array
    {
        return ['holding_site_template' => ['is_active' => true],'estimate_price_region' => ['is_supported' => true],
            'active_estimate_price_version' => ['status' => 'active'],'approved_estimate_dataset' => ['status' => 'parsed']];
    }
    public static function actorColumns(): array
    {
        return ['report_run_card' => 'requester_actor_id','report_export_card' => 'requester_actor_id',
            'report_saved_view_card' => 'owner_id','report_saved_view_revision' => 'owner_id','report_subscription_card' => 'owner_id',
            'report_delivery_card' => 'owner_id','estimate_generation_session_card' => 'user_id','estimate_generation_document_card' => 'user_id',
            'estimate_generation_feedback_card' => 'user_id'];
    }
    public static function rowColumnMatches(): array
    {
        return ['holding_allocation_fact' => ['contributor_organization_id' => 'organization_id'],
            'holding_performance_row' => ['contributor_organization_id' => 'organization_id']];
    }
    public static function parentColumns(): array
    {
        $p = static fn (string $type, bool $nullable = false): array => ['type' => $type,'nullable' => $nullable];
        $result = ['mdm_change_request' => ['mdm_record_id' => $p('mdm_record', true)],
            'mdm_change_event' => ['change_request_id' => $p('mdm_change_request')], 'mdm_change_log' => ['mdm_record_id' => $p('mdm_record', true)],
            'mdm_duplicate_member' => ['duplicate_group_id' => $p('mdm_duplicate_group')], 'mdm_merge_run' => ['duplicate_group_id' => $p('mdm_duplicate_group')],
            'holding_site_card' => ['organization_group_id' => $p('organization_group_card')],
            'holding_site_page' => ['holding_site_id' => $p('holding_site_card')], 'holding_site_block' => ['holding_site_id' => $p('holding_site_card'), 'holding_site_page_id' => $p('holding_site_page', true) + ['matches' => ['holding_site_id' => 'holding_site_id']]],
            'holding_site_revision' => ['holding_site_id' => $p('holding_site_card')], 'holding_site_asset' => ['holding_site_id' => $p('holding_site_card')],
            'holding_site_lead' => ['holding_site_id' => $p('holding_site_card'), 'holding_site_page_id' => $p('holding_site_page', true) + ['matches' => ['holding_site_id' => 'holding_site_id']]],
            'holding_site_collaborator' => ['holding_site_id' => $p('holding_site_card')],
            'holding_contract_evidence' => ['contract_id' => $p('contract')], 'holding_project_allocation_card' => ['contract_id' => $p('contract')],
            'holding_work_event' => ['contract_id' => $p('contract'),'performance_act_id' => $p('performance_act')],
            'holding_payment_event' => ['contract_id' => $p('contract', true),'payment_document_id' => $p('payment_document')],
            'holding_allocation_fact' => ['contract_id' => $p('contract')], 'holding_performance_row' => ['snapshot_id' => $p('holding_performance_snapshot')],
            'intercompany_flow_row' => ['snapshot_id' => $p('intercompany_flow_snapshot'),'allocation_id' => $p('holding_project_allocation_card') + ['match_project' => true]],
            'report_export_card' => ['run_id' => $p('report_run_card')], 'report_saved_view_revision' => ['saved_view_id' => $p('report_saved_view_card')],
            'report_subscription_card' => ['saved_view_id' => $p('report_saved_view_card')],
            'report_delivery_card' => ['subscription_id' => $p('report_subscription_card'),'run_id' => $p('report_run_card', true),'export_id' => $p('report_export_card', true)],
            'estimate_generation_package_card' => ['session_id' => $p('estimate_generation_session_card')],
            'estimate_generation_item_card' => ['package_id' => $p('estimate_generation_package_card')],
            'estimate_generation_feedback_card' => ['session_id' => $p('estimate_generation_session_card')]];
        $result += [
            'approved_construction_resource' => ['dataset_version_id' => $p('approved_estimate_dataset')],
            'approved_estimate_norm_collection' => ['dataset_version_id' => $p('approved_estimate_dataset')],
            'approved_estimate_norm_section' => ['collection_id' => $p('approved_estimate_norm_collection')],
            'approved_estimate_norm' => ['collection_id' => $p('approved_estimate_norm_collection'),'section_id' => $p('approved_estimate_norm_section',true) + ['matches' => ['collection_id' => 'collection_id']]],
            'approved_estimate_norm_resource' => ['estimate_norm_id' => $p('approved_estimate_norm'),'construction_resource_id' => $p('approved_construction_resource',true)],
            'estimate_price_zone' => ['estimate_region_id' => $p('estimate_price_region')],
            'active_estimate_price_version' => ['region_id' => $p('estimate_price_region'),'price_zone_id' => $p('estimate_price_zone') + ['matches' => ['estimate_region_id' => 'region_id']], 'period_id' => $p('estimate_price_period')],
            'active_estimate_price_activation' => ['active_version_id' => $p('active_estimate_price_version') + ['matches' => ['region_id' => 'region_id','price_zone_id' => 'price_zone_id']]],
            'approved_estimate_resource_price' => ['construction_resource_id' => $p('approved_construction_resource',true)],
        ];
        foreach (self::fields() as $type => $fields) {
            if (str_starts_with($type, 'estimate_generation_') && in_array('session_id', $fields, true)) {
                $result[$type]['session_id'] = $p('estimate_generation_session_card') + (in_array('project_id', $fields, true) ? ['match_project' => true] : []);
            }
            if (in_array('document_id', $fields, true)) { $result[$type]['document_id'] = $p('estimate_generation_document_card') + ['matches' => ['session_id' => 'session_id'],'match_project' => true]; }
            if (in_array('page_id', $fields, true)) { $result[$type]['page_id'] = $p('estimate_generation_page_card', true) + ['matches' => ['document_id' => 'document_id'],'match_project' => true]; }
        }
        return $result;
    }
    public static function applyPublicationScope(string $type, \Illuminate\Database\Eloquent\Builder $query): void
    {
        $table = $query->getModel()->getTable();
        if ($type === 'approved_estimate_dataset' || $type === 'approved_estimate_resource_price') {
            $datasets = self::publishedDatasetQueries();
            $column = $type === 'approved_estimate_dataset' ? 'id' : 'dataset_version_id';
            if ($type === 'approved_estimate_dataset') {
                $query->where(static function (\Illuminate\Database\Eloquent\Builder $scope) use ($datasets,$table,$column): void {
                    $scope->whereRaw('1 = 0');
                    foreach ($datasets as $dataset) { $scope->orWhereIn($table.'.'.$column,$dataset); }
                });
            } else {
                $active = self::activeRegionalPriceVersions()->select(['id', 'region_id', 'price_zone_id', 'period_id']);
                $query->where(static function (\Illuminate\Database\Eloquent\Builder $scope) use ($datasets,$table,$active): void {
                    $scope->whereIn(\Illuminate\Support\Facades\DB::raw('('.$table.'.regional_price_version_id, '.$table.'.region_id, '.$table.'.price_zone_id, '.$table.'.period_id)'),$active)->orWhere(static function (\Illuminate\Database\Eloquent\Builder $base) use ($datasets,$table): void {
                        $base->whereNull($table.'.regional_price_version_id')->where(static function (\Illuminate\Database\Eloquent\Builder $approved) use ($datasets,$table): void {
                            $approved->whereRaw('1 = 0');
                            foreach ($datasets as $dataset) { $approved->orWhereIn($table.'.dataset_version_id',$dataset); }
                        });
                    });
                });
                $query->where($table.'.base_price','>',0);
            }
        }
        if ($type === 'approved_estimate_norm_collection') {
            $query->whereIn($table.'.dataset_version_id',\App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion::query()->where('source_type','fsnb_2022')->select('id'));
        }
        if ($type === 'active_estimate_price_version') {
            $query->whereIn($table.'.id',\App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceActivation::query()
                ->whereColumn('region_id',$table.'.region_id')->whereColumn('price_zone_id',$table.'.price_zone_id')->select('active_version_id'));
        }
    }

    private static function publishedDatasetQueries(): array
    {
        $datasets = [];
        foreach (['fsnb_2022', 'fsbc', 'fgis_labor_prices'] as $source) {
            $datasets[] = \App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion::query()
                ->where('source_type', $source)->where('status', 'parsed')->whereNotNull('finished_at')
                ->where('rows_imported', '>', 0)->where('errors_count', 0)
                ->orderByDesc('finished_at')->orderByDesc('id')->limit(1)->select('id');
        }

        return $datasets;
    }

    private static function activeRegionalPriceVersions(): \Illuminate\Database\Eloquent\Builder
    {
        $active = self::records()['active_estimate_price_version'][0]::query()->where('status', 'active');
        self::applyPublicationScope('active_estimate_price_version', $active);

        return $active->whereIn('region_id', self::records()['estimate_price_region'][0]::query()->where('is_supported', true)->select('id'));
    }

    public static function publishedPriceScopes(): \Generator
    {
        foreach (self::activeRegionalPriceVersions()->get(['id', 'region_id', 'price_zone_id', 'period_id']) as $version) {
            yield ['regional_price_version_id' => $version->id, 'region_id' => $version->region_id,
                'price_zone_id' => $version->price_zone_id, 'period_id' => $version->period_id];
        }
        foreach (self::publishedDatasetQueries() as $dataset) {
            $id = $dataset->value('id');
            if ($id !== null) { yield ['regional_price_version_id' => null, 'dataset_version_id' => $id]; }
        }
    }

    public static function publishedDatasetIds(): \Generator
    {
        foreach (self::publishedDatasetQueries() as $dataset) {
            $id = $dataset->value('id');
            if ($id !== null) { yield (int) $id; }
        }
    }
    public static function applyActorScope(string $type, \Illuminate\Database\Eloquent\Builder $query, \App\Models\User $actor, int $organizationId,
        \App\Domain\Authorization\Services\AuthorizationService $authorization, \App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy $policy): void
    {
        if (! isset(self::records()[$type])) { return; }
        self::applyPublicationScope($type,$query);
        if (in_array($type,['mdm_record','mdm_change_request','mdm_change_log','mdm_duplicate_member'],true)) {
            self::applyMdmEntityScope($query,$actor,$organizationId,$policy);
        }
        if ($type === 'mdm_duplicate_group') {
            $members = \App\BusinessModules\Core\Mdm\Models\MdmDuplicateMember::query();
            self::applyMdmEntityScope($members,$actor,$organizationId,$policy);
            $members->select('mdm_duplicate_members.id');
            $query->whereIn('mdm_duplicate_groups.id',(clone $members)->select('mdm_duplicate_members.duplicate_group_id'))
                ->whereNotIn('mdm_duplicate_groups.id',\App\BusinessModules\Core\Mdm\Models\MdmDuplicateMember::query()
                    ->whereNotIn('mdm_duplicate_members.id',$members)->select('mdm_duplicate_members.duplicate_group_id'));
        }
        if (self::records()[$type][2] !== 'report_cards' || $type === 'report_delivery_card') { return; }
        $table = $query->getModel()->getTable();
        try {
            $allowed = $policy->allowedReportCodes($actor, $organizationId, $authorization);
        } catch (\Throwable) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn($table.'.report_code',$allowed);
        if (! in_array($type,['report_run_card','report_export_card'],true)) { return; }
        $projects = $policy->entityQuery($actor,$organizationId,'project');
        if ($projects === null) { $query->whereRaw($table.".scope_project_ids = '[]'::jsonb"); }
        else {
            $projects->select([])->selectRaw('CAST(projects.id AS TEXT)');
            $query->whereRaw('NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text('.$table.'.scope_project_ids) AS scope_project(id) WHERE scope_project.id NOT IN ('.$projects->toSql().'))',$projects->getBindings());
        }
        $query->whereRaw('NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text('.$table.'.scope_holding_organization_ids) AS scope_org(id) WHERE scope_org.id <> ?)',[(string) $organizationId]);
        $query->whereRaw($table.".scope_resources = '[]'::jsonb");
    }
    private static function applyMdmEntityScope(\Illuminate\Database\Eloquent\Builder $query, \App\Models\User $actor, int $organizationId,
        \App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy $policy): void
    {
        $table = $query->getModel()->getTable();
        $models = ['contractor' => \App\Models\Contractor::class,'supplier' => \App\Models\Supplier::class,
            'material' => \App\Models\Material::class,'measurement_unit' => \App\Models\MeasurementUnit::class,'work_type' => \App\Models\WorkType::class,
            'cost_category' => \App\Models\CostCategory::class,'estimate_position' => \App\Models\EstimatePositionCatalog::class,
            'estimate_position_category' => \App\Models\EstimatePositionCatalogCategory::class,
            'budget_article' => \App\BusinessModules\Features\Budgeting\Models\BudgetArticle::class,
            'responsibility_center' => \App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter::class,
            'project' => \App\Models\Project::class,'contract' => \App\Models\Contract::class];
        $query->where(static function (\Illuminate\Database\Eloquent\Builder $scope) use ($models,$actor,$organizationId,$policy,$table): void {
            $scope->whereRaw('1 = 0');
            foreach ($models as $kind => $class) {
                $entityType = $policy->entityTypeForModel($class);
                if ($entityType === null) { continue; }
                $parent = $policy->entityQuery($actor,$organizationId,$entityType);
                if ($parent === null) { continue; }
                $parentTable = $parent->getModel()->getTable();
                $scope->orWhere(static fn (\Illuminate\Database\Eloquent\Builder $linked): \Illuminate\Database\Eloquent\Builder => $linked
                    ->where($table.'.entity_type',$kind)->whereIn($table.'.entity_id',$parent->select($parentTable.'.'.$parent->getModel()->getKeyName())));
            }
        });
    }
    public static function versionColumns(): array
    {
        $result = [];
        foreach (self::fields() as $type => $fields) {
            $result[$type] = match ($type) {
                'holding_contract_evidence','holding_work_event','holding_payment_event','holding_allocation_fact' => ['recorded_at'],
                'holding_performance_snapshot','intercompany_flow_snapshot' => ['generated_at'],
                'holding_performance_row','intercompany_flow_row' => [],
                'report_saved_view_revision','mdm_change_log' => ['created_at'],
                default => ['updated_at'],
            };
        }
        return $result;
    }
    public static function safeSelectColumns(): array
    {
        $result = [];
        foreach (self::fields() as $type => $fields) { $result[$type] = array_values(array_unique([...$fields,...self::versionColumns()[$type],...array_keys(self::parentColumns()[$type] ?? [])])); }
        return $result;
    }
    public static function structuredFields(): array { return array_values(array_unique(array_merge(...array_values(self::fields())))); }
    public static function numericFields(): array
    {
        return ['quality_score','confidence','version','revision','score','min_acceptable_score','total_rows','accepted_rows','rejected_rows',
            'file_size','total_amount','amount','amount_minor','contracted_minor','accepted_accrual_minor','cash_minor','internal_minor',
            'external_minor','unclassified_minor','total_minor','progress','processing_progress','progress_percent','page_count','processed_page_count',
            'page_number','value_number','quantity','generation_progress','actual_items_count','unit_price','direct_cost','overhead_cost','profit_cost','total_cost',
            'base_price','machine_salary_price','machine_price_without_salary','depth','year','quarter'];
    }
    public static function factFieldGroups(): array
    {
        $money = ['total_amount','amount','amount_minor','contracted_minor','accepted_accrual_minor','cash_minor','internal_minor','external_minor',
            'unclassified_minor','total_minor','unit_price','direct_cost','overhead_cost','profit_cost','total_cost','base_price','machine_salary_price','machine_price_without_salary'];
        return ['money' => $money,'quantity' => array_values(array_diff(self::numericFields(),$money)),
            'status' => ['status','processing_stage','generation_stage','before_status','after_status','quality_status','freshness_status'],
            'date' => ['last_synced_at','submitted_at','approved_at','applied_at','created_at','resolved_at','published_at','recorded_at','occurred_at',
                'recognized_on','generated_at','stale_at','period_start','queued_at','ready_at','expires_at','next_run_at','scheduled_for','notified_at','accepted_at','started_at','finished_at','invalidated_at']];
    }
    public static function fieldLabels(): array
    {
        return ['report_code' => 'Код отчёта','requester_actor_id' => 'Автор запроса','owner_id' => 'Владелец',
            'processing_progress' => 'Готовность обработки, %','processing_stage' => 'Этап обработки','generation_progress' => 'Готовность сметы, %',
            'generation_stage' => 'Этап составления сметы','actual_items_count' => 'Позиции сметы','quality_score' => 'Оценка качества',
            'page_count' => 'Страниц документа','processed_page_count' => 'Обработано страниц','contributor_organization_id' => 'Организация данных',
            'contracted_minor' => 'Сумма договоров, коп.','accepted_accrual_minor' => 'Принято работ, коп.','cash_minor' => 'Оплачено, коп.',
            'internal_minor' => 'Внутренний поток, коп.','external_minor' => 'Внешний поток, коп.','unclassified_minor' => 'Нераспределённый поток, коп.',
            'total_minor' => 'Итого, коп.','amount_minor' => 'Сумма, коп.','visibility' => 'Видимость','frequency' => 'Периодичность',
            'format' => 'Формат','next_run_at' => 'Следующая подготовка','progress' => 'Готовность, %','confidence' => 'Достоверность',
            'min_acceptable_score' => 'Минимальная оценка качества','accepted_rows' => 'Принято строк','rejected_rows' => 'Отклонено строк','total_rows' => 'Всего строк'];
    }
    public static function entityLabels(): array { return array_map(static fn (array $row): string => $row[3], self::records()); }
    public static function navigationTemplates(): array
    {
        return array_map(static fn (array $row): string => match ($row[2]) {
            'mdm','mdm_requests' => '/mdm', 'holding_site','holding_templates' => '/holding',
            'holding_finance','holding_reports','report_cards' => '/reports', default => '/estimates',
        }, self::records());
    }
    public static function observerDefinitions(): array
    {
        $result = [];
        foreach (self::records() as $type => $row) { $result[$row[0]] = ['organization_reporting',$type]; }
        return $result;
    }
    public static function technicalExclusions(): array
    {
        return ['canonical_query_json','definition_snapshot','execution_input_bytes','content_json','totals','payload','metadata',
            'raw_payload','normalized_payload','input_payload','draft_payload','analysis_payload','storage_path','public_url',
            'source_refs','scope_holding_organization_ids','scope_project_ids','email','phone','ip_address','user_agent','bindings',
            'settings','published_payload','theme_config','analytics_config','one_c_lock_summary','bank_details','claim_token','execution_lease_token'];
    }
    public static function excludedModels(): array
    {
        $estimate = 'App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\';
        $report = 'App\\BusinessModules\\Core\\Reporting\\Infrastructure\\Persistence\\Models\\';
        return [
            'App\\BusinessModules\\Core\\Mdm\\Models\\MdmRelationship' => 'Полиморфная служебная связь; нет конечного контракта прав обеих сторон.',
            'App\\BusinessModules\\Core\\MultiOrganization\\Models\\OrganizationMetrics' => 'Материализованный межорганизационный агрегат; нет текущего проектного ACL исходных сумм.',
            'App\\BusinessModules\\Core\\MultiOrganization\\Reporting\\Models\\HoldingAllocationProjectionGap' => 'Служебная диагностика проекции, произвольный источник без конечного ACL.',
            'App\\BusinessModules\\Core\\MultiOrganization\\Reporting\\Models\\HoldingPaymentEventCoverageCheckpoint' => 'Глобальный технический checkpoint полноты платежного журнала.',
            $report.'ReportAuditIntentRecord' => 'Внутренний журнал аудита и повторной доставки.',
            $report.'ReportDispatchIntentRecord' => 'Внутренний outbox исполнения отчёта.',
            $report.'ReportWorkspacePreferencesRecord' => 'Приватные настройки интерфейса пользователя.',
            $report.'ReportSourceSnapshotRecord' => 'Внутренний снимок произвольного набора; нет конечного ACL исходных строк.',
            $report.'ReportSourceSnapshotRowRecord' => 'Произвольный payload набора без декларации полей и текущих прав.',
            $report.'ReportSourceSnapshotDrillRowRecord' => 'Произвольный drill payload без декларации полей и текущих прав.',
            $estimate.'EstimateGenerationAiRoleRun' => 'Внутренний вызов провайдера и модельные сообщения.',
            $estimate.'EstimateGenerationAiUsage' => 'Технический журнал стоимости провайдера.',
            $estimate.'EstimateGenerationAuditEvent' => 'Внутренний журнал с произвольным payload.',
            $estimate.'EstimateGenerationBenchmarkRun' => 'Системный benchmark моделей и обучающих данных.',
            $estimate.'EstimateGenerationFailure' => 'Внутренний диагностический журнал ошибок.',
            $estimate.'EstimateGenerationPipelineCheckpoint' => 'Внутренний cache/checkpoint с lease и модельным payload.',
            $estimate.'EstimateGenerationProcessingUnit' => 'Внутренний job с lease и артефактами обработки.',
            $estimate.'EstimateGenerationTargetedRebuildOperation' => 'Внутренняя операция с lease, hashes и модельным delta.',
            $estimate.'EstimateGenerationTrainingDataset' => 'Системное обучение; нет клиентского контракта чтения набора.',
            $estimate.'EstimateGenerationTrainingExample' => 'Системное обучение; исходные данные не имеют клиентского контракта чтения.',
            $estimate.'EstimateGenerationTrainingFile' => 'Системное обучение; пути файлов и системные права.',
            'App\\BusinessModules\\Addons\\EstimateGeneration\\Normatives\\Models\\EstimateImportError' => 'Внутренняя диагностика загрузки нормативов с путями и raw fragment.',
        ];
    }
    public static function existingCoverage(): array
    {
        return ['App\\BusinessModules\\Addons\\EstimateGeneration\\Models\\EstimateGenerationLearningExample' => 'estimate_generation_learning_example'];
    }
    public static function domainDefinitions(): array
    {
        $result = [];
        foreach (self::domainGates() as $domain => [$module,$permissions]) {
            $types = array_keys(array_filter(self::records(), static fn (array $row): bool => $row[2] === $domain));
            $fields = array_values(array_unique(array_merge(...array_map(static fn (string $type): array => self::fields()[$type], $types))));
            $entity = ['type' => 'string','enum' => $types];
            $fieldSchema = ['type' => ['array','null'],'items' => ['type' => 'string','enum' => $fields]];
            $schema = static fn (array $properties): array => ['type' => 'object','properties' => $properties,'required' => array_keys($properties),'additionalProperties' => false];
            $schemas = ['search' => $schema(['entity_type' => $entity,'query' => ['type' => 'string','maxLength' => 200], 'project_id' => ['type' => ['integer','null']], 'limit' => ['type' => 'integer','minimum' => 1,'maximum' => 20],'fields' => $fieldSchema]),
                'read' => $schema(['entity_type' => $entity,'id' => ['type' => ['integer','string'],'maxLength' => 64],'fields' => $fieldSchema]),
                'navigation' => $schema(['entity_type' => $entity,'id' => ['type' => ['integer','string'],'maxLength' => 64]])];
            $result[] = new AssistantDomainDefinition($domain,$module,$types[0],$permissions,$fields,$schemas,array_keys($schemas), self::navigationTemplates()[$types[0]],'organization_reporting',$types,[],array_combine($types,array_map(static fn (string $type): string => self::entityPermissions()[$type][0],$types)));
        }
        return $result;
    }
}
