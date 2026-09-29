<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use Illuminate\Database\Eloquent\Model;

final class AssistantStructuredFactFormatter
{
    public const MAX_ROWS = 25;

    private const FIELDS = [
        'id', 'name', 'title', 'subject', 'number', 'document_number', 'order_number', 'request_number', 'budget_currency',
        'incident_number', 'position_number', 'status', 'stage_code', 'pipeline_code', 'severity', 'address',
        'email', 'worker_name', 'code', 'asset_code', 'inventory_number', 'version', 'currency',
        'document_type', 'incident_type', 'item_type', 'resource_type', 'date', 'start_date', 'end_date',
        'estimate_date', 'planned_start_date', 'planned_end_date', 'planned_finish_date', 'required_date',
        'due_date', 'needed_by', 'order_date', 'delivery_date', 'paid_at', 'completion_date', 'work_date', 'expected_close_at', 'valid_until', 'sent_at',
        'published_at', 'is_billable', 'is_active', 'is_paid', 'quantity', 'quantity_total',
        'total_quantity', 'completed_quantity', 'unit_price', 'total_amount', 'total_amount_with_vat', 'amount', 'budget_amount', 'planned_advance_amount', 'actual_advance_amount', 'hours',
        'hours_worked', 'volume_completed', 'progress_percent', 'reading_time', 'project_id', 'contract_id',
        'estimate_id', 'estimate_section_id', 'estimate_item_id', 'work_order_id', 'schedule_id',
        'warehouse_id', 'material_id', 'asset_id', 'machinery_asset_id', 'document_set_id', 'work_type_id',
        'purchase_request_id', 'supplier_request_id', 'purchase_order_id', 'scope_id', 'session_id',
        'company_id', 'primary_contact_id', 'owner_user_id',
        'package_id', 'artifact_id', 'document_title', 'document_code', 'artifact_type', 'stage', 'project_stage', 'discipline',
        'planned_issue_date', 'issued_at', 'resolved_at', 'body', 'response', 'author_id', 'assignee_id', 'version_number',
        'revision', 'revision_label', 'source_format', 'file_format', 'source_original_name', 'source_mime_type', 'source_size_bytes', 'model_date', 'is_current',
        'priority', 'request_type', 'user_id', 'assigned_to', 'material_name', 'material_quantity', 'material_unit',
        'personnel_count', 'equipment_count', 'work_start_date', 'work_end_date', 'rental_start_date', 'rental_end_date', 'equipment_start_at', 'equipment_end_at',
    ];

    private const NUMERIC_FIELDS = ['quantity', 'quantity_total', 'total_quantity', 'completed_quantity', 'budget_amount', 'planned_advance_amount', 'actual_advance_amount', 'unit_price', 'total_amount',
        'total_amount_with_vat', 'amount', 'hours', 'hours_worked', 'volume_completed', 'progress_percent', 'reading_time', 'material_quantity', 'personnel_count', 'equipment_count'];

    private const ENTITY_TYPES = [
        'project', 'warehouse', 'site_request', 'estimate', 'estimate_section', 'estimate_item', 'estimate_item_resource', 'contract',
        'design_package', 'design_artifact', 'design_artifact_version', 'design_review_comment', 'design_model_set',
        'payment_document', 'purchase_request', 'supplier_request', 'supplier_proposal',
        'supplier_proposal_decision', 'purchase_order', 'purchase_receipt', 'procurement_approval',
        'procurement_audit_event', 'schedule', 'schedule_task', 'warehouse_balance', 'warehouse_movement',
        'warehouse_project_allocation', 'asset_reservation', 'inventory_act', 'warehouse_storage_cell',
        'warehouse_task', 'warehouse_asset', 'project_material_delivery', 'completed_work',
        'performance_act', 'user', 'production_labor_work_order', 'production_labor_work_order_line',
        'production_labor_timesheet', 'production_labor_timesheet_entry', 'production_labor_output_entry',
        'production_labor_payroll_accrual', 'time_entry', 'machinery_asset', 'machinery_assignment',
        'machinery_shift_report', 'machinery_downtime', 'machinery_maintenance_order',
        'machinery_fuel_issue', 'machinery_production_record', 'quality_defect', 'executive_document',
        'executive_document_set', 'safety_incident', 'safety_violation', 'safety_work_permit',
        'safety_briefing', 'safety_corrective_action', 'safety_inspection', 'safety_inspection_finding',
        'change_request', 'change_management_rfi', 'change_impact', 'change_approval', 'variation_order',
        'change_claim', 'acceptance_scope', 'acceptance_checklist', 'acceptance_checklist_item',
        'acceptance_finding', 'acceptance_session', 'acceptance_signoff', 'handover_package',
        'handover_package_document', 'project_location', 'crm_deal', 'crm_lead', 'crm_company',
        'crm_contact', 'crm_activity', 'customer_issue', 'commercial_proposal', 'knowledge_article',
    ];

    public static function row(Model $model, string $entityType, array $returnedFields, array $reference): ?array
    {
        $attributes = $model->attributesToArray();
        $values = [];
        foreach (array_intersect($returnedFields, array_merge(self::FIELDS, AssistantExtendedDomainRegistry::values('structuredFields'))) as $field) {
            if (in_array($field, ['description', 'notes', 'content_plain_text'], true)) { continue; }
            if (! array_key_exists($field, $attributes)) {
                continue;
            }
            $value = $attributes[$field];
            if (in_array($field, array_merge(self::NUMERIC_FIELDS, AssistantExtendedDomainRegistry::values('numericFields')), true)) {
                $raw = $model->getRawOriginal($field);
                if ($raw !== null && ((! is_string($raw) && ! is_int($raw)) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $raw))) {
                    continue;
                }
                if ($raw !== null) {
                    $cast = (string) ($model->getCasts()[$field] ?? '');
                    $scale = preg_match('/^decimal:(\d+)$/D', $cast, $match) ? (int) $match[1]
                        : (str_contains((string) $raw, '.') ? strlen(explode('.', (string) $raw, 2)[1]) : 0);
                    $value = FinanceDecimal::value($raw, $scale);
                    if (strlen($value) > 255) {
                        continue;
                    }
                }
            }
            if ($value !== null && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                continue;
            }
            $values[$field] = is_string($value) ? self::boundedText($value) : $value;
        }
        if ($values === []) {
            return null;
        }
        $row = ['entity_type' => $entityType, 'entity_id' => $model->getKey(), 'fields' => $values,
            'source_ref' => $reference, 'source_version' => $reference['source_version'] ?? null];
        $row['version'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));

        return $row;
    }

    public static function payload(array $rows, string $fetchedAt): array
    {
        $rows = array_slice($rows, 0, self::MAX_ROWS);
        if ($rows === []) {
            return [];
        }
        $evidence = ['rows' => $rows, 'source_refs' => array_column($rows, 'source_ref'), 'fetched_at' => $fetchedAt,
            'version' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'scope' => 'returned_entity_fields', 'validation_status' => 'partial'];
        $lines = [trans_message('ai_assistant_facts.returned_scope')];
        $entityLabels = AssistantExtendedDomainRegistry::values('entityLabels');
        $fieldLabels = AssistantExtendedDomainRegistry::values('fieldLabels');
        $allowedFields = array_merge(self::FIELDS, AssistantExtendedDomainRegistry::values('structuredFields'));
        foreach ($rows as $row) {
            $lines[] = trans_message('ai_assistant_facts.entity', ['type' => $entityLabels[$row['entity_type']] ?? (in_array($row['entity_type'], self::ENTITY_TYPES, true) ? trans_message('ai_assistant_facts.entities.'.$row['entity_type']) : trans_message('ai_assistant_facts.record')),
                'id' => self::markdownText((string) $row['entity_id'])]);
            foreach ($row['fields'] as $field => $value) {
                if (! in_array($field, $allowedFields, true)) {
                    continue;
                }
                $display = $value === null ? trans_message('ai_assistant_facts.unknown')
                    : (is_bool($value) ? trans_message('ai_assistant_facts.'.($value ? 'yes' : 'no')) : AssistantStructuredFactLabels::display($row['entity_type'], $field, (string) $value));
                $numeric = in_array($field, array_merge(self::NUMERIC_FIELDS, AssistantExtendedDomainRegistry::values('numericFields')), true) && preg_match('/^-?\d+(?:\.\d+)?$/D', $display);
                $label = in_array($field, self::FIELDS, true) ? trans_message('ai_assistant_facts.fields.'.$field)
                    : ($fieldLabels[$field] ?? trans_message('ai_assistant_facts.fields.'.$field));
                $lines[] = $label.': '.($numeric ? $display : self::markdownText($display));
            }
            $url = $row['source_ref']['navigation']['url'] ?? null;
            if (is_string($url) && preg_match('#^/(?!/)[^\s\[\]()<>\\\\]+$#D', $url)) {
                $lines[] = '['.trans_message('ai_assistant_facts.open_record').']('.$url.')';
            }
        }
        $lines[] = trans_message('ai_assistant_facts.fetched_at', ['time' => self::markdownText($fetchedAt)]);

        return ['structured_fact_evidence' => $evidence, 'server_formatted_facts' => implode("\n", $lines), 'validation_status' => 'partial'];
    }

    private static function boundedText(string $value): string
    {
        $value = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? '';

        return mb_strlen($value) > 255 ? mb_substr($value, 0, 254).'…' : $value;
    }

    private static function markdownText(string $value): string
    {
        $escapes = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];
        foreach (str_split('!"#$%\'()*+,-./:;=?@[\\]^_`{|}~') as $character) {
            $escapes[$character] = '\\'.$character;
        }

        return strtr(self::boundedText($value), $escapes);
    }
}
