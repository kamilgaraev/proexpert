<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\DesignAdditionalRagSource;

final class AssistantDesignAdditionalMetadata
{
    public static function records(): array
    {
        return [
            'design_package_section' => ['DesignPackageSection', ['id','project_id','package_id','code','title','project_stage','object_type','status','required','sort_order','normative_reference'], 'documents.view'],
            'design_review_round' => ['DesignReviewRound', ['id','project_id','package_id','round_number','review_type','status','started_at','closed_at'], 'review'],
            'design_review_issue_mapping' => ['DesignReviewCommentIssueMapping', ['id','project_id','legacy_design_review_comment_id','quality_defect_id'], 'review'],
            'design_model_set_revision' => ['DesignModelSetRevision', ['id','model_set_id','revision'], 'models.view'],
            'design_document_sheet' => ['DesignDocumentSheet', ['id','project_id','package_id','section_id','artifact_id','version_id','sheet_number','sheet_code','sheet_title','revision','file_page_number','total_sheets','status'], 'documents.view'],
            'design_document_template' => ['DesignDocumentTemplate', ['id','normative_source_id','profile_code','project_stage','object_type','section_code','section_title','document_code','document_title','artifact_type','required','sort_order','sheet_registry_required','normative_reference'], 'normative_catalog.view'],
            'design_source_link' => ['DesignSourceLink', ['id','project_id','source_version_id','source_sheet_id','source_element_id','status','row_version','ended_at'], 'view'],
            'design_composition_revision' => ['DesignCompositionRevision', ['id','project_id','package_id','revision_number','state_version','status','approved_at'], 'view'],
            'design_composition_exclusion' => ['DesignCompositionExclusion', ['id','project_id','package_id','revision_id','item_key'], 'view'],
            'design_impact_review' => ['DesignImpactReview', ['id','project_id','link_id','previous_version_id','new_version_id','status','decision','decided_at'], 'view'],
            'design_completeness_check' => ['DesignCompletenessCheck', ['id','project_id','package_id','status','profile_code','project_stage','object_type','checked_at','blocking_count','warning_count'], 'documents.view'],
            'design_workflow_event' => ['DesignWorkflowEvent', ['id','project_id','package_id','action','from_status','to_status','created_at'], 'view'],
            'design_ifc_model_element' => ['DesignIfcModelElement', ['id','project_id','version_id','derivative_id','express_id','global_id','category','name'], 'models.view'],
            'design_model_derivative' => ['DesignModelDerivative', ['id','project_id','version_id','derivative_format','status','progress_percent','processing_stage','prepared_at','processing_started_at','processing_finished_at'], 'models.view'],
            'design_model_session' => ['DesignModelSession', ['id','project_id','model_set_id','model_set_revision_id','title'], 'models.view'],
            'design_ifc_upload_session' => ['DesignIfcUploadSession', ['id','project_id','package_id','original_name','mime_type','size_bytes','parts_count','status','completed_version_id','expires_at','cleaned_at'], 'models.view'],
            'bim_progress_group' => ['BimConstructionProgressGroup', ['id','project_id','version_id','schedule_task_id','title','floor','zone','work_kind','status','revision','ended_at'], 'models.view'],
            'bim_progress_group_element' => ['BimConstructionProgressGroupElement', ['id','group_id','version_id','schedule_task_id','element_id','status','ended_at'], 'models.view'],
            'bim_progress_group_history' => ['BimConstructionProgressGroupHistory', ['id','project_id','group_id','version_id','action','revision','created_at'], 'models.view'],
            'design_normative_source' => ['DesignNormativeSource', ['id','code','title','version','effective_from','effective_to','status'], 'normative_catalog.view'],
        ];
    }

    public static function entityDefinitions(): array
    {
        $result = [];
        foreach (self::records() as $type => [$class, $fields, $permission]) {
            $result[$type] = ['design_additional', 'App\\BusinessModules\\Features\\DesignManagement\\Models\\'.$class,
                $permission === 'normative_catalog.view' ? 'design_normative' : 'design_detail'];
        }
        return $result;
    }

    public static function fields(): array { return array_map(static fn (array $record): array => $record[1], self::records()); }
    public static function domainGates(): array
    {
        return ['design_detail' => ['design-management', ['design-management.view']],
            'design_normative' => ['design-management', ['design-management.normative_catalog.view']]];
    }
    public static function entityPermissions(): array
    {
        return array_map(static fn (array $record): array => ['design-management.'.$record[2]], self::records());
    }
    public static function sourcePermissions(): array { return []; }
    public static function sourceClasses(): array { return [DesignAdditionalRagSource::class]; }
    public static function organizationColumns(): array
    {
        return array_fill_keys(['design_model_set_revision','bim_progress_group_element','design_normative_source','design_document_template'], null);
    }
    public static function globalCatalogEntities(): array { return ['design_normative_source' => true, 'design_document_template' => true]; }
    public static function globalFanoutEntities(): array { return self::globalCatalogEntities(); }
    public static function rowPredicates(): array { return ['design_normative_source' => ['status' => 'active']]; }

    public static function parentColumns(): array
    {
        $parent = static fn (string $type, bool $nullable = false, bool $matchProject = true): array => ['type' => $type, 'nullable' => $nullable, 'match_project' => $matchProject];
        return [
            'design_package_section' => ['package_id' => $parent('design_package')],
            'design_review_round' => ['package_id' => $parent('design_package')],
            'design_review_issue_mapping' => ['legacy_design_review_comment_id' => $parent('design_review_comment', true), 'quality_defect_id' => $parent('quality_defect')],
            'design_model_set_revision' => ['model_set_id' => $parent('design_model_set', false, false)],
            'design_document_sheet' => ['package_id' => $parent('design_package'), 'section_id' => $parent('design_package_section', true),
                'artifact_id' => $parent('design_artifact', true), 'version_id' => $parent('design_artifact_version', true) + ['matches' => ['artifact_id' => 'artifact_id']]],
            'design_document_template' => ['normative_source_id' => $parent('design_normative_source', true, false)],
            'design_source_link' => ['source_version_id' => $parent('design_artifact_version'),
                'source_sheet_id' => $parent('design_document_sheet', true) + ['matches' => ['version_id' => 'source_version_id']],
                'source_element_id' => $parent('design_ifc_model_element', true) + ['key' => 'express_id', 'matches' => ['version_id' => 'source_version_id']]],
            'design_composition_revision' => ['package_id' => $parent('design_package')],
            'design_composition_exclusion' => ['package_id' => $parent('design_package'), 'revision_id' => $parent('design_composition_revision') + ['matches' => ['package_id' => 'package_id']]],
            'design_impact_review' => ['link_id' => $parent('design_source_link'), 'previous_version_id' => $parent('design_artifact_version'), 'new_version_id' => $parent('design_artifact_version')],
            'design_completeness_check' => ['package_id' => $parent('design_package')],
            'design_workflow_event' => ['package_id' => $parent('design_package')],
            'design_ifc_model_element' => ['version_id' => $parent('design_artifact_version'), 'derivative_id' => $parent('design_model_derivative', true) + ['matches' => ['version_id' => 'version_id']]],
            'design_model_derivative' => ['version_id' => $parent('design_artifact_version')],
            'design_model_session' => ['model_set_id' => $parent('design_model_set'), 'model_set_revision_id' => $parent('design_model_set_revision', false, false) + ['matches' => ['model_set_id' => 'model_set_id']]],
            'design_ifc_upload_session' => ['package_id' => $parent('design_package'), 'completed_version_id' => $parent('design_artifact_version', true)],
            'bim_progress_group' => ['version_id' => $parent('design_artifact_version'), 'schedule_task_id' => $parent('schedule_task', true, false)],
            'bim_progress_group_element' => ['group_id' => $parent('bim_progress_group', false, false) + ['matches' => ['version_id' => 'version_id']],
                'version_id' => $parent('design_artifact_version', false, false), 'schedule_task_id' => $parent('schedule_task', true, false),
                'element_id' => $parent('design_ifc_model_element', false, false) + ['key' => 'express_id', 'matches' => ['version_id' => 'version_id']]],
            'bim_progress_group_history' => ['group_id' => $parent('bim_progress_group') + ['matches' => ['version_id' => 'version_id']], 'version_id' => $parent('design_artifact_version')],
        ];
    }

    public static function safeSelectColumns(): array
    {
        $result = [];
        foreach (self::records() as $type => [$class, $fields]) {
            $result[$type] = array_values(array_unique([...$fields, 'updated_at', ...array_keys(self::parentColumns()[$type] ?? []),
                ...(array_key_exists($type, self::organizationColumns()) ? [] : ['organization_id'])]));
        }
        return $result;
    }
    public static function observerDefinitions(): array
    {
        $result = [];
        foreach (self::entityDefinitions() as $type => [$source, $class]) { $result[$class] = [$source, $type]; }
        return $result;
    }
    public static function technicalExclusions(): array
    {
        return ['geometry','properties','classifications','transforms','version_ids','source_url','source_path','s3_upload_id','file_identity',
            'derivative_file_path','uploaded_parts','completion','payload','metadata','snapshot','source_snapshot','target_snapshot','target_type','target_id',
            'composition','results','summary','failed_reason','idempotency_key'];
    }
    public static function structuredFields(): array { return array_values(array_unique(array_merge(...array_values(self::fields())))); }
    public static function navigationTemplates(): array { return array_fill_keys(array_keys(self::records()), '/design-management'); }
    public static function entityLabels(): array
    {
        return array_combine(array_keys(self::records()), ['Раздел комплекта','Раунд проверки','Связь замечания с дефектом','Редакция набора моделей','Лист документа',
            'Шаблон проектного документа','Связь с проектным источником','Редакция состава','Исключение из состава','Проверка влияния изменений','Проверка комплектности',
            'Событие согласования','Элемент BIM-модели','Подготовка модели к просмотру','Сессия просмотра моделей','Загрузка IFC-модели','Группа строительного прогресса',
            'Элемент группы прогресса','История группы прогресса','Нормативный источник']);
    }
    public static function fieldLabels(): array
    {
        return ['round_number' => 'Номер раунда', 'review_type' => 'Вид проверки', 'sheet_number' => 'Номер листа', 'sheet_code' => 'Шифр листа',
            'sheet_title' => 'Название листа', 'file_page_number' => 'Страница файла', 'total_sheets' => 'Всего листов', 'profile_code' => 'Профиль комплектности',
            'project_stage' => 'Стадия проектирования', 'object_type' => 'Тип объекта', 'section_code' => 'Шифр раздела', 'section_title' => 'Название раздела',
            'document_code' => 'Шифр документа', 'document_title' => 'Название документа', 'required' => 'Обязателен', 'normative_reference' => 'Нормативная ссылка',
            'revision_number' => 'Номер редакции', 'state_version' => 'Версия состояния', 'blocking_count' => 'Блокирующих замечаний', 'warning_count' => 'Предупреждений',
            'progress_percent' => 'Готовность просмотра, %', 'parts_count' => 'Частей загрузки', 'floor' => 'Этаж', 'zone' => 'Зона', 'work_kind' => 'Вид работ',
            'category' => 'Категория элемента', 'effective_from' => 'Действует с', 'effective_to' => 'Действует до'];
    }
    public static function factFieldGroups(): array
    {
        return ['status' => ['status','from_status','to_status','decision'], 'quantity' => ['blocking_count','warning_count','total_sheets','parts_count','progress_percent','revision','revision_number','round_number'],
            'date' => ['started_at','closed_at','approved_at','checked_at','prepared_at','processing_started_at','processing_finished_at','expires_at','cleaned_at','ended_at','effective_from','effective_to']];
    }
    public static function domainDefinitions(): array
    {
        $result = [];
        foreach (self::domainGates() as $domain => [$module, $permissions]) {
            $types = array_keys(array_filter(self::entityDefinitions(), static fn (array $record): bool => $record[2] === $domain));
            $fields = array_values(array_unique(array_merge(...array_map(static fn (string $type): array => self::fields()[$type], $types))));
            $entity = ['type' => 'string', 'enum' => $types];
            $id = ['type' => ['integer','string'], 'maxLength' => 64];
            $fieldSchema = ['type' => ['array','null'], 'items' => ['type' => 'string', 'enum' => $fields]];
            $schema = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
            $schemas = ['search' => $schema(['entity_type' => $entity, 'query' => ['type' => 'string', 'maxLength' => 200], 'project_id' => ['type' => ['integer','null']], 'limit' => ['type' => 'integer','minimum' => 1,'maximum' => 20], 'fields' => $fieldSchema]),
                'read' => $schema(['entity_type' => $entity, 'id' => $id, 'fields' => $fieldSchema]), 'navigation' => $schema(['entity_type' => $entity, 'id' => $id])];
            $entityPermissions = array_map(static fn (string $type): string => self::entityPermissions()[$type][0], array_combine($types, $types));
            $result[] = new AssistantDomainDefinition($domain, $module, $types[0], $permissions, $fields, $schemas, array_keys($schemas), '/design-management', 'design_additional', $types, [], $entityPermissions);
        }
        return $result;
    }
}
