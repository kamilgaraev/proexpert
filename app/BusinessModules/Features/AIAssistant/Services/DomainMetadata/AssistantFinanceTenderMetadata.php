<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\AdvanceAccountingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\BudgetingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\TenderRagSource;

final class AssistantFinanceTenderMetadata
{
    public static function sourceClasses(): array
    {
        return [BudgetingRagSource::class, TenderRagSource::class, AdvanceAccountingRagSource::class];
    }

    public static function entityDefinitions(): array
    {
        $entities = [];
        foreach (self::inventory() as $type => $definition) {
            $domain = self::domainFor($type);
            $entities[$type] = [$domain, $definition['model'], $domain];
        }

        return $entities;
    }

    public static function domainGates(): array
    {
        $gates = [];
        foreach (self::entityPermissions() as $type => $permissions) {
            $domain = self::domainFor($type);
            $gates[$domain] ??= [str_replace('_', '-', $domain), []];
            $gates[$domain][1] = array_values(array_unique([...$gates[$domain][1], ...array_filter($permissions, static fn (string $permission): bool => $permission !== 'finance.view')]));
        }

        return $gates;
    }

    public static function entityPermissions(): array
    {
        $permissions = [];
        foreach (array_keys(self::inventory()) as $type) {
            $permission = match (true) {
                $type === 'advance_account_setting' => 'advance_settings.view',
                $type === 'advance_account_transaction' => 'advance_transactions.view',
                str_starts_with($type, 'tender') => 'tenders.view',
                str_starts_with($type, 'wip_forecast') => 'budgeting.wip_forecast.view',
                str_starts_with($type, 'management_pnl') => 'budgeting.management_pnl.view',
                str_starts_with($type, 'project_control') => 'budgeting.wip_forecast.view',
                str_starts_with($type, 'project_finance') => 'budgeting.plan_fact.view',
                $type === 'cash_gap_opening_balance' => 'budgeting.cash_gap.view',
                str_starts_with($type, 'portfolio_'), $type === 'budgeting_portfolio_snapshot', $type === 'project_portfolio_health_projection' => 'budgeting.portfolio_dashboard.view',
                str_starts_with($type, 'budget_limit') => 'budgeting.limits.view',
                $type === 'budget_period' => 'budgeting.periods.view',
                $type === 'budget_period_closure' => 'budgeting.periods.close_status.view',
                $type === 'budget_scenario' => 'budgeting.scenarios.view',
                str_starts_with($type, 'budget_article') => 'budgeting.articles.view',
                $type === 'responsibility_center' => 'budgeting.cfo.view',
                str_starts_with($type, 'budget_import') => 'budgeting.import.preview',
                str_starts_with($type, 'budgeting_report_source') => 'budgeting.audit.view',
                str_starts_with($type, 'epm_data_mart') => 'budgeting.plan_fact.view',
                default => 'budgeting.budgets.view',
            };
            $permissions[$type] = [$permission];
            if ($type === 'wip_forecast_audit_event') {
                $permissions[$type][] = 'budgeting.wip_forecast.view_audit';
            }
            if (str_starts_with($type, 'wip_forecast')) {
                $permissions[$type][] = 'budgeting.wip_forecast.view_sensitive_costs';
            }
        }

        return $permissions;
    }

    public static function sourcePermissions(): array
    {
        return ['budgeting' => ['finance.view'], 'tenders' => ['tenders.amounts.view'], 'advance_accounting' => ['finance.view']];
    }

    public static function observerDefinitions(): array
    {
        $observers = [];
        foreach (self::entityDefinitions() as $type => [$source, $model]) {
            $observers[$model] = [$source, $type];
        }

        return $observers;
    }

    public static function parentColumns(): array
    {
        $parent = static fn (string $type, bool $nullable = false, string $key = 'id'): array => ['type' => $type, 'nullable' => $nullable, 'key' => $key];
        $parents = [
            'budget_version' => ['budget_period_id' => $parent('budget_period'), 'scenario_id' => $parent('budget_scenario')],
            'budget_line' => ['budget_version_id' => $parent('budget_version'), 'budget_article_id' => $parent('budget_article'), 'responsibility_center_id' => $parent('responsibility_center', true)],
            'budget_amount' => ['budget_line_id' => $parent('budget_line')],
            'budget_import_batch' => ['budget_version_id' => $parent('budget_version')],
            'budget_import_row' => ['budget_import_batch_id' => $parent('budget_import_batch')],
            'budget_period_closure' => ['budget_period_id' => $parent('budget_period')],
            'budget_article_mapping' => ['budget_article_id' => $parent('budget_article')],
            'budget_limit_reservation' => ['budget_limit_check_id' => $parent('budget_limit_check', true)],
            'epm_data_mart_aggregate' => ['snapshot_id' => $parent('epm_data_mart_snapshot')],
            'epm_data_mart_recalculation_run' => ['snapshot_id' => $parent('epm_data_mart_snapshot', true)],
            'management_pnl_snapshot' => ['policy_id' => $parent('management_pnl_policy')],
            'management_pnl_record' => ['snapshot_id' => $parent('management_pnl_snapshot')],
            'project_finance_row' => ['snapshot_id' => $parent('project_finance_snapshot')],
            'project_control_snapshot' => ['baseline_version_id' => $parent('project_control_baseline_version', true)],
            'project_control_row' => ['snapshot_id' => $parent('project_control_snapshot')],
            'project_portfolio_health_projection' => ['snapshot_id' => $parent('budgeting_portfolio_snapshot')],
            'portfolio_liquidity_projection' => ['snapshot_id' => $parent('budgeting_portfolio_snapshot')],
            'budgeting_report_source_watermark_record' => ['close_id' => $parent('budgeting_report_source_close_record', false, 'close_id')],
            'tender' => ['source_id' => $parent('tender_source', true), 'customer_company_id' => $parent('crm_company', true),
                'customer_contact_id' => $parent('crm_contact', true), 'crm_deal_id' => $parent('crm_deal', true),
                'commercial_proposal_id' => $parent('commercial_proposal', true)],
            'tender_competitor' => ['crm_company_id' => $parent('crm_company', true)],
            'tender_deadline_reminder' => ['deadline_id' => $parent('tender_deadline', true) + ['matches' => ['tender_id' => 'tender_id']]],
        ];
        foreach (self::inventory() as $type => $definition) {
            if (str_starts_with($type, 'wip_forecast_') && $type !== 'wip_forecast_version') {
                $parents[$type]['forecast_version_id'] = $parent('wip_forecast_version');
            }
            if (str_starts_with($type, 'tender_') && $type !== 'tender_source') {
                $parents[$type]['tender_id'] = $parent('tender');
            }
            if (in_array('project_id', $definition['fields'], true)) {
                $parents[$type]['project_id'] = $parent('project', true);
            }
            if (in_array('contract_id', $definition['fields'], true)) {
                $parents[$type]['contract_id'] = $parent('contract', true);
            }
        }

        return $parents;
    }

    public static function organizationAggregates(): array
    {
        return ['budget_import_batch' => true, 'budget_import_row' => true, 'budgeting_report_source_close_record' => true,
            'budgeting_report_source_watermark_record' => true, 'management_pnl_snapshot' => true, 'project_finance_snapshot' => true,
            'budgeting_portfolio_snapshot' => true, 'portfolio_liquidity_source_version' => true,
            'epm_data_mart_recalculation_run' => true, 'wip_forecast_version' => 'project_id',
            'epm_data_mart_snapshot' => 'project_id', 'epm_data_mart_aggregate' => 'project_id'];
    }

    public static function numericFields(): array
    {
        return ['plan_amount', 'forecast_amount', 'requested_amount', 'amount', 'percent', 'balance_after', 'max_single_issue_amount',
            'dual_authorization_threshold', 'high_value_notification_threshold', 'initial_max_price', 'expected_bid_amount',
            'final_bid_amount', 'winner_amount', 'bid_amount', 'score', 'bac', 'percent_complete', 'ev', 'pv', 'ac', 'wip_total',
            'ctc', 'etc', 'ftc', 'eac', 'forecast_revenue_at_completion', 'forecast_gross_margin', 'forecast_margin_percent', 'cpi', 'spi',
            'revenue', 'cost', 'margin', 'margin_percent', 'gross_margin_percent', 'wip', 'opening', 'inflow', 'outflow', 'closing', 'gap', 'revenue_minor',
            'direct_cost_minor', 'gross_margin_minor', 'operating_expense_minor', 'operating_result_minor', 'plan_revenue_minor',
            'actual_revenue_minor', 'forecast_revenue_minor', 'plan_cost_minor', 'actual_cost_minor', 'forecast_cost_minor', 'margin_minor',
            'plan_minor', 'actual_minor', 'committed_minor', 'available_minor', 'variance_minor', 'bac_minor', 'pv_minor', 'ev_minor',
            'ac_minor', 'wip_minor', 'ctc_minor', 'eac_minor', 'forecast_variance_minor', 'approved_etc_minor', 'sv_minor', 'cv_minor'];
    }

    public static function structuredFields(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::publicFields()))));
    }

    public static function publicFields(): array
    {
        $provenance = ['source_refs', 'source_row_refs', 'source_hash', 'source_snapshot_hash', 'source_snapshot',
            'source_manifest', 'source_coverage', 'source_payload', 'content_hash', 'definition_hash', 'query_hash',
            'scope_hash', 'closure_hash', 'source_snapshot_id', 'source_snapshot_kind'];
        $opaque = [
            'budget_import_row' => ['raw_payload'],
            'epm_data_mart_aggregate' => ['dimensions', 'metrics'],
            'epm_data_mart_recalculation_run' => ['filters', 'error_summary'],
            'epm_data_mart_snapshot' => ['filters', 'payload', 'freshness'],
            'management_pnl_snapshot' => ['component_snapshots', 'totals', 'warnings'],
            'budgeting_portfolio_snapshot' => ['totals', 'watermarks'],
            'portfolio_liquidity_projection' => ['quality_gaps', 'warnings'],
            'portfolio_liquidity_source_version' => ['payload'],
            'project_control_baseline_version' => [],
            'project_control_row' => ['payload'],
            'project_control_snapshot' => ['watermarks', 'totals', 'row_schema'],
            'project_finance_snapshot' => ['totals'],
            'wip_forecast_line' => ['formula_components', 'comparison'],
            'wip_forecast_version' => ['summary', 'formulas', 'freshness', 'actions', 'meta'],
        ];
        $mixedScopeMoney = ['epm_data_mart_aggregate', 'epm_data_mart_snapshot', 'budgeting_portfolio_snapshot',
            'project_portfolio_health_projection', 'portfolio_liquidity_projection', 'portfolio_liquidity_source_version',
            'project_control_row', 'project_control_snapshot', 'project_finance_row', 'project_finance_snapshot'];
        $fields = [];
        foreach (self::inventory() as $type => $definition) {
            $excluded = [...$provenance, ...($opaque[$type] ?? [])];
            if (in_array($type, $mixedScopeMoney, true)) { $excluded = [...$excluded, ...self::numericFields()]; }
            $fields[$type] = array_values(array_diff($definition['fields'], $excluded));
        }

        return $fields;
    }

    public static function fields(): array { return self::publicFields(); }

    public static function safeSelectColumns(): array { return self::publicFields(); }

    public static function publicInventory(): array
    {
        $inventory = self::inventory();
        $fields = self::publicFields();
        foreach ($inventory as $type => &$definition) { $definition['fields'] = $fields[$type]; }
        unset($definition);
        return $inventory;
    }

    public static function factFieldGroups(): array
    {
        $nonMoney = ['percent', 'percent_complete', 'forecast_margin_percent', 'margin_percent', 'gross_margin_percent', 'cpi', 'spi', 'score'];
        $fields = self::structuredFields();

        return ['money' => array_values(array_diff(self::numericFields(), $nonMoney)),
            'quantity' => [...$nonMoney, 'rank', 'row_count', 'attempt_count', 'attempts_count', 'duplicate_source_count', 'coverage_numerator', 'coverage_denominator', 'size'],
            'date' => array_values(array_filter($fields, static fn (string $field): bool => str_ends_with($field, '_at') || str_ends_with($field, '_date')
                || in_array($field, ['period', 'month', 'period_month', 'period_start', 'period_end', 'period_from', 'period_to', 'as_of', 'valid_from', 'valid_until', 'active_from', 'active_to', 'scheduled_for', 'retained_until', 'reopened_until'], true))),
            'status' => ['status', 'closure_status', 'reporting_status', 'quality_status', 'freshness_status', 'validation_status', 'mapping_status', 'reconciliation_status', 'decision', 'go_no_go_decision', 'risk_level'],
            'owner' => ['owner_user_id', 'actor_user_id', 'responsible_user_id', 'approver_user_id', 'created_by', 'created_by_user_id', 'approved_by', 'approved_by_user_id', 'user_id']];
    }

    public static function versionColumns(): array
    {
        $columns = [];
        foreach (self::inventory() as $type => $definition) {
            $columns[$type] = ['updated_at', ...array_values(array_intersect(['generated_at', 'recorded_at', 'approved_at', 'created_at'], $definition['fields']))];
        }

        return $columns;
    }

    public static function entityLabels(): array
    {
        $labels = [];
        foreach (array_keys(self::inventory()) as $type) {
            $labels[$type] = trans_message('ai_assistant_finance_tenders.entities.'.$type);
        }

        return $labels;
    }

    public static function fieldLabels(): array
    {
        $labels = [];
        foreach (self::structuredFields() as $field) {
            $labels[$field] = trans_message('ai_assistant_finance_tenders.fields.'.$field);
        }

        return $labels;
    }

    public static function organizationNullableCatalogs(): array
    {
        return ['tender_source' => true];
    }

    public static function domainDefinitions(): array
    {
        $definitions = [];
        $publicFields = self::publicFields();
        foreach (self::domainGates() as $domain => [$module, $permissions]) {
            $entities = array_keys(array_filter(self::entityDefinitions(), static fn (array $definition): bool => $definition[2] === $domain));
            $fields = [];
            foreach ($entities as $type) {
                $fields = [...$fields, ...$publicFields[$type]];
            }
            $fields = array_values(array_unique($fields));
            $id = ['type' => ['integer', 'string'], 'minimum' => 1, 'minLength' => 1, 'maxLength' => 36,
                'pattern' => '^(?:[1-9][0-9]*|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[0-9A-HJKMNP-TV-Z]{26})$',
                'description' => 'Existing numeric ID, UUID or ULID; never an organization supplied by the client.'];
            $type = ['type' => 'string', 'enum' => $entities];
            $fieldList = ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => $fields]];
            $schema = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
            $schemas = [
                'read' => $schema(['entity_type' => $type, 'id' => $id, 'fields' => $fieldList]),
                'search' => $schema(['entity_type' => $type, 'query' => ['type' => 'string', 'maxLength' => 200], 'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20], 'fields' => $fieldList]),
                'navigation' => $schema(['entity_type' => $type, 'id' => $id]),
            ];
            $fieldPermissions = [];
            foreach ($fields as $field) {
                if (in_array($field, self::numericFields(), true) || in_array($field, ['payload', 'metrics', 'totals', 'source_snapshot', 'summary', 'formula_components', 'comparison', 'old_values', 'new_values', 'raw_payload', 'normalized_payload', 'metadata', 'source_manifest', 'source_refs', 'source_row_refs', 'source_coverage', 'workflow_history', 'audit_trail', 'mapping_payload', 'preview_summary', 'error_summary'], true)) {
                    $fieldPermissions[$field] = $domain === 'tenders' ? ['tenders.amounts.view'] : ['finance.view'];
                    if ($domain === 'budgeting' && in_array($field, ['bac', 'ev', 'pv', 'ac', 'ctc', 'etc', 'ftc', 'eac', 'wip_total', 'forecast_revenue_at_completion', 'forecast_gross_margin', 'forecast_margin_percent', 'source_snapshot', 'formula_components', 'comparison'], true)) {
                        $fieldPermissions[$field][] = 'budgeting.wip_forecast.view_sensitive_costs';
                    }
                }
            }
            $definitions[] = new AssistantDomainDefinition($domain, $module, $entities[0], [], $fields, $schemas, array_keys($schemas),
                match ($domain) { 'budgeting' => '/budgeting', 'tenders' => '/tenders', default => '/payments?tab=accountable' }, $domain, $entities,
                $fieldPermissions, array_intersect_key(self::entityPermissions(), array_flip($entities)));
        }

        return $definitions;
    }

    public static function domainFor(string $type): string
    {
        return str_starts_with($type, 'tender') ? 'tenders' : (str_starts_with($type, 'advance_account') ? 'advance_accounting' : 'budgeting');
    }

    public static function excludedModels(): array
    {
        return [\App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityBackfillCheckpoint::class => 'Internal ingestion leases/cursors are not business content.'];
    }

    public static function attachmentDefinitions(): array
    {
        return ['tender_file' => ['path' => 'stored_path', 'name' => 'original_name', 'mime' => 'mime_type', 'parent_type' => 'tender',
            'parent_column' => 'tender_id', 'inherits_parent_permissions' => true, 'metadata_only' => true, 'storage_unverified' => true],
            'advance_account_transaction' => ['ids' => 'attachment_ids', 'delimiter' => ',', 'parent_type' => 'advance_account_transaction',
                'inherits_parent_permissions' => true, 'legacy_non_s3_unsupported' => true]];
    }

    public static function attachmentCoverageDefinitions(): array
    {
        return ['tender_file' => ['status' => 'storage_unverified', 'message' => trans_message('ai_assistant_finance_tenders.attachment_storage_unverified')]];
    }

    public static function inventory(): array
    {
        return [
            'budget_amount' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetAmount::class, 'fields' => ['id', 'budget_line_id', 'month', 'plan_amount', 'forecast_amount', 'currency']],
            'budget_article' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetArticle::class, 'fields' => ['id', 'uuid', 'organization_id', 'parent_id', 'code', 'name', 'budget_kind', 'flow_direction', 'management_cost_class', 'is_leaf', 'is_active', 'cost_category_id']],
            'budget_article_mapping' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetArticleMapping::class, 'fields' => ['id', 'uuid', 'organization_id', 'budget_article_id', 'system', 'one_c_base_id', 'integration_profile_id', 'external_code', 'external_name', 'mapping_status', 'mapping_payload']],
            'budget_import_batch' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetImportBatch::class, 'fields' => ['id', 'uuid', 'organization_id', 'budget_version_id', 'source_format', 'status', 'template_code', 'mapping_mode', 'uploaded_by', 'preview_summary', 'error_summary', 'committed_at', 'committed_by']],
            'budget_import_row' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetImportRow::class, 'fields' => ['id', 'budget_import_batch_id', 'row_number', 'raw_payload', 'normalized_payload', 'validation_status', 'validation_errors', 'validation_warnings']],
            'budgeting_report_source_close_record' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetingReportSourceCloseRecord::class, 'fields' => ['id', 'close_id', 'report_code', 'organization_id', 'period_start', 'period_end', 'scenario_identity', 'plan_identity', 'formula_version', 'source_manifest', 'content_hash', 'approved_by', 'approved_at', 'retained_until', 'status', 'restates_close_id', 'restated_by', 'restated_at', 'restated_by_close_id']],
            'budgeting_report_source_watermark_record' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetingReportSourceWatermarkRecord::class, 'fields' => ['id', 'close_id', 'source', 'cutoff_at', 'watermark', 'source_schema_version']],
            'budget_limit_check' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetLimitCheck::class, 'fields' => ['id', 'uuid', 'organization_id', 'payment_document_id', 'payment_transaction_id', 'operation_type', 'operation_id', 'budget_period_id', 'budget_article_id', 'responsibility_center_id', 'project_id', 'contract_id', 'counterparty_id', 'period_month', 'currency', 'requested_amount', 'status', 'decision', 'message', 'required_permission', 'accepted', 'checked_by_user_id', 'overridden_by_user_id', 'override_reason', 'sources', 'summary', 'dimensions', 'audit_trail']],
            'budget_limit_reservation' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetLimitReservation::class, 'fields' => ['id', 'uuid', 'organization_id', 'payment_document_id', 'budget_limit_check_id', 'budget_period_id', 'budget_article_id', 'responsibility_center_id', 'project_id', 'contract_id', 'counterparty_id', 'period_month', 'currency', 'amount', 'status', 'reserved_at', 'released_at', 'converted_at', 'release_reason', 'created_by_user_id', 'metadata']],
            'budget_line' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetLine::class, 'fields' => ['id', 'uuid', 'budget_version_id', 'budget_article_id', 'responsibility_center_id', 'project_id', 'contract_id', 'counterparty_id', 'currency', 'description', 'metadata']],
            'budget_period' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetPeriod::class, 'fields' => ['id', 'uuid', 'organization_id', 'code', 'name', 'period_type', 'starts_at', 'ends_at', 'status']],
            'budget_period_closure' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetPeriodClosure::class, 'fields' => ['id', 'uuid', 'budget_period_id', 'closure_status', 'closure_mode', 'reason', 'closed_by', 'closed_at', 'reopened_until', 'metadata']],
            'budget_scenario' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetScenario::class, 'fields' => ['id', 'uuid', 'organization_id', 'code', 'name', 'scenario_type', 'is_default', 'is_active']],
            'budget_version' => ['model' => \App\BusinessModules\Features\Budgeting\Models\BudgetVersion::class, 'fields' => ['id', 'uuid', 'organization_id', 'budget_period_id', 'scenario_id', 'budget_kind', 'version_number', 'name', 'description', 'status', 'submitted_at', 'approved_at', 'activated_at', 'workflow_history', 'created_by', 'submitted_by', 'approved_by', 'activated_by']],
            'cash_gap_opening_balance' => ['model' => \App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance::class, 'fields' => ['id', 'uuid', 'organization_id', 'balance_date', 'currency', 'amount', 'status', 'note', 'created_by_user_id', 'approved_by_user_id', 'approved_at', 'audit_trail', 'metadata']],
            'epm_data_mart_aggregate' => ['model' => \App\BusinessModules\Features\Budgeting\Models\EpmDataMartAggregate::class, 'fields' => ['id', 'uuid', 'snapshot_id', 'organization_id', 'report_scope', 'scope_hash', 'aggregate_key', 'formula_version', 'source_hash', 'period_start', 'period_end', 'as_of_date', 'project_id', 'currency', 'dimensions', 'metrics', 'source_refs', 'generated_at']],
            'epm_data_mart_recalculation_run' => ['model' => \App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun::class, 'fields' => ['id', 'uuid', 'organization_id', 'report_scope', 'scope_hash', 'status', 'formula_version', 'source_hash', 'snapshot_id', 'filters', 'source_refs', 'error_summary', 'requested_by', 'queued_at', 'started_at', 'finished_at', 'generated_at', 'duration_ms', 'attempts_count']],
            'epm_data_mart_snapshot' => ['model' => \App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot::class, 'fields' => ['id', 'uuid', 'organization_id', 'report_scope', 'scope_hash', 'status', 'formula_version', 'source_hash', 'period_start', 'period_end', 'as_of_date', 'project_id', 'currency', 'filters', 'payload', 'freshness', 'source_refs', 'generated_at', 'stale_at', 'superseded_at']],
            'responsibility_center' => ['model' => \App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter::class, 'fields' => ['id', 'uuid', 'organization_id', 'parent_id', 'center_type', 'code', 'name', 'owner_user_id', 'approver_user_id', 'linked_entity_type', 'linked_entity_id', 'active_from', 'active_to', 'is_active']],
            'wip_forecast_adjustment' => ['model' => \App\BusinessModules\Features\Budgeting\Models\WipForecastAdjustment::class, 'fields' => ['id', 'uuid', 'forecast_version_id', 'organization_id', 'scope', 'scope_id', 'project_id', 'stage_id', 'contract_id', 'estimate_item_id', 'period', 'adjustment_type', 'formula_component', 'amount', 'percent', 'currency', 'reason', 'owner_user_id', 'status', 'valid_from', 'valid_until', 'affects_formulas', 'source_snapshot_hash', 'approved_by', 'rejected_by', 'approved_at', 'rejected_at']],
            'wip_forecast_assumption' => ['model' => \App\BusinessModules\Features\Budgeting\Models\WipForecastAssumption::class, 'fields' => ['id', 'uuid', 'forecast_version_id', 'organization_id', 'assumption_type', 'scope', 'scope_id', 'title', 'description', 'amount', 'percent', 'currency', 'status', 'owner_user_id', 'valid_until', 'source_row_refs', 'source_snapshot_hash']],
            'wip_forecast_audit_event' => ['model' => \App\BusinessModules\Features\Budgeting\Models\WipForecastAuditEvent::class, 'fields' => ['id', 'uuid', 'forecast_version_id', 'organization_id', 'event_type', 'actor_user_id', 'reason', 'old_values', 'new_values', 'source_snapshot_hash', 'created_at']],
            'wip_forecast_line' => ['model' => \App\BusinessModules\Features\Budgeting\Models\WipForecastLine::class, 'fields' => ['id', 'uuid', 'forecast_version_id', 'organization_id', 'project_id', 'stage_id', 'contract_id', 'estimate_item_id', 'period', 'currency', 'bac', 'percent_complete', 'ev', 'pv', 'ac', 'wip_total', 'ctc', 'etc', 'ftc', 'eac', 'forecast_revenue_at_completion', 'forecast_gross_margin', 'forecast_margin_percent', 'cpi', 'spi', 'progress_source', 'quality_status', 'group_values', 'dimensions', 'problem_flags', 'risk_flags', 'source_row_refs', 'formula_components', 'comparison', 'source_snapshot_hash']],
            'wip_forecast_version' => ['model' => \App\BusinessModules\Features\Budgeting\Models\WipForecastVersion::class, 'fields' => ['id', 'uuid', 'organization_id', 'project_id', 'budget_version_id', 'scenario_id', 'previous_version_id', 'version_number', 'name', 'description', 'status', 'period_start', 'period_end', 'as_of_date', 'currency', 'group_by', 'source_snapshot_hash', 'source_snapshot', 'summary', 'formulas', 'source_coverage', 'freshness', 'actions', 'meta', 'workflow_history', 'created_by', 'submitted_by', 'approved_by', 'activated_by', 'submitted_at', 'approved_at', 'activated_at']],
            'management_pnl_policy' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlPolicy::class, 'fields' => ['id', 'organization_id', 'version', 'status', 'classification_rules', 'allocation_rules', 'policy_hash', 'activated_at', 'activated_by']],
            'management_pnl_record' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlRecord::class, 'fields' => ['id', 'snapshot_id', 'organization_id', 'row_key', 'project_id', 'responsibility_center_id', 'budget_article_id', 'period', 'scenario', 'currency', 'revenue_minor', 'direct_cost_minor', 'gross_margin_minor', 'operating_expense_minor', 'operating_result_minor', 'gross_margin_percent', 'policy_version', 'source_refs']],
            'management_pnl_snapshot' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlSnapshot::class, 'fields' => ['id', 'organization_id', 'policy_id', 'policy_version', 'definition_hash', 'formula_version', 'scope_hash', 'query_hash', 'source_hash', 'component_snapshots', 'as_of', 'generated_at', 'stale_at', 'row_count', 'totals', 'coverage_numerator', 'coverage_denominator', 'quality_status', 'warnings']],
            'budgeting_portfolio_snapshot' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot::class, 'fields' => ['id', 'organization_id', 'report_code', 'as_of', 'definition_hash', 'source_hash', 'query_hash', 'formula_version', 'source_schema_version', 'quality_status', 'freshness_status', 'totals', 'watermarks', 'source_refs', 'row_count', 'generated_at', 'stale_at']],
            'portfolio_liquidity_projection' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityProjection::class, 'fields' => ['id', 'organization_id', 'snapshot_id', 'forecast_date', 'project_id', 'project_name', 'currency', 'scenario', 'opening', 'inflow', 'outflow', 'closing', 'gap', 'quality_status', 'duplicate_source_count', 'quality_gaps', 'warnings', 'reconciliation_status', 'row_key', 'source_refs']],
            'portfolio_liquidity_source_gap' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceGap::class, 'fields' => ['id', 'organization_id', 'source_type', 'source_id', 'missing_fields', 'source_hash', 'observed_at', 'business_effective_at', 'recorded_at', 'resolved_at']],
            'portfolio_liquidity_source_version' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceVersion::class, 'fields' => ['id', 'organization_id', 'source_type', 'source_id', 'source_version', 'occurred_at', 'created_at', 'recorded_at', 'effective_at', 'history_complete', 'payload', 'source_hash']],
            'project_portfolio_health_projection' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\ProjectPortfolioHealthProjection::class, 'fields' => ['id', 'organization_id', 'snapshot_id', 'project_id', 'project_name', 'currency', 'as_of', 'risk_rank', 'risk_level', 'revenue', 'cost', 'margin', 'margin_percent', 'wip', 'ftc', 'eac', 'ctc', 'row_key', 'source_refs']],
            'project_control_baseline_version' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlBaselineVersion::class, 'fields' => ['id', 'organization_id', 'project_id', 'schedule_id', 'version_number', 'approved_at', 'approved_by', 'source_hash', 'source_payload']],
            'project_control_row' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlRow::class, 'fields' => ['id', 'organization_id', 'snapshot_id', 'row_key', 'project_id', 'task_id', 'wbs_code', 'contractor_id', 'cost_center_id', 'currency', 'bac_minor', 'pv_minor', 'ev_minor', 'ac_minor', 'approved_etc_minor', 'sv_minor', 'cv_minor', 'spi', 'cpi', 'eac_minor', 'payload', 'source_refs']],
            'project_control_snapshot' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot::class, 'fields' => ['id', 'organization_id', 'project_id', 'baseline_version_id', 'status_date', 'wip_version', 'progress_watermark', 'actual_cost_watermark', 'formula_version', 'definition_hash', 'query_hash', 'source_hash', 'generated_at', 'stale_at', 'watermarks', 'totals', 'source_refs', 'row_schema', 'row_count']],
            'project_finance_row' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceRow::class, 'fields' => ['id', 'snapshot_id', 'organization_id', 'report_code', 'row_key', 'project_id', 'project_name', 'responsibility_center_id', 'responsibility_center_name', 'budget_article_id', 'article_name', 'wbs_id', 'wbs_code', 'budget_version_id', 'forecast_version_id', 'period', 'scenario', 'currency', 'currency_source', 'tax_basis', 'direction', 'cost_class', 'plan_revenue_minor', 'actual_revenue_minor', 'forecast_revenue_minor', 'plan_cost_minor', 'actual_cost_minor', 'forecast_cost_minor', 'margin_minor', 'margin_percent', 'plan_minor', 'actual_minor', 'committed_minor', 'available_minor', 'variance_minor', 'risk', 'bac_minor', 'pv_minor', 'ev_minor', 'ac_minor', 'wip_minor', 'ctc_minor', 'eac_minor', 'forecast_variance_minor', 'spi', 'cpi', 'quality_status', 'source_refs']],
            'project_finance_snapshot' => ['model' => \App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot::class, 'fields' => ['id', 'organization_id', 'report_code', 'definition_hash', 'formula_version', 'source_schema_version', 'scope_hash', 'query_hash', 'source_hash', 'source_snapshot_kind', 'source_snapshot_id', 'source_snapshot_hash', 'period_from', 'period_to', 'as_of', 'budget_version_id', 'forecast_version_id', 'closure_hash', 'row_count', 'totals', 'source_refs', 'quality_status', 'coverage_numerator', 'coverage_denominator', 'generated_at', 'stale_at']],
            'tender' => ['model' => \App\BusinessModules\Features\Tenders\Models\Tender::class, 'fields' => ['id', 'organization_id', 'source_id', 'customer_company_id', 'customer_contact_id', 'owner_user_id', 'crm_deal_id', 'commercial_proposal_id', 'project_id', 'contract_id', 'number', 'external_number', 'external_url', 'title', 'description', 'customer_name', 'customer_inn', 'customer_kpp', 'customer_ogrn', 'status', 'priority', 'risk_level', 'initial_max_price', 'budget_missing_reason', 'expected_bid_amount', 'final_bid_amount', 'final_bid_amount_missing_reason', 'winner_amount', 'currency', 'published_at', 'questions_deadline_at', 'submission_deadline_at', 'submitted_at', 'submitted_by_user_id', 'submission_confirmation_file_id', 'submission_confirmation_url', 'opening_at', 'auction_at', 'result_expected_at', 'result_published_at', 'next_deadline_at', 'go_no_go_decision', 'go_no_go_reason', 'decided_by_user_id', 'decided_at', 'lost_reason', 'cancel_reason', 'winner_name', 'requirements_summary', 'analysis_summary', 'requirements', 'evaluation_criteria', 'metadata', 'created_by_user_id', 'updated_by_user_id']],
            'tender_competitor' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderCompetitor::class, 'fields' => ['id', 'tender_id', 'crm_company_id', 'name', 'inn', 'kpp', 'bid_amount', 'score', 'rank', 'is_winner', 'notes', 'metadata']],
            'tender_deadline' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderDeadline::class, 'fields' => ['id', 'tender_id', 'kind', 'title', 'due_at', 'completed_at', 'responsible_user_id', 'reminder_policy', 'is_required', 'metadata']],
            'tender_deadline_reminder' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderDeadlineReminder::class, 'fields' => ['id', 'organization_id', 'tender_id', 'deadline_id', 'policy_key', 'channel', 'scheduled_for', 'sent_at', 'failed_at', 'status', 'attempt_count', 'metadata']],
            'tender_file' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderFile::class, 'fields' => ['id', 'tender_id', 'category', 'original_name', 'mime_type', 'size', 'uploaded_by_user_id', 'uploaded_at', 'metadata']],
            'tender_requirement' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderRequirement::class, 'fields' => ['id', 'tender_id', 'kind', 'title', 'description', 'is_required', 'required_for_status', 'status', 'owner_user_id', 'due_at', 'completed_at', 'metadata']],
            'tender_risk' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderRisk::class, 'fields' => ['id', 'tender_id', 'kind', 'severity', 'title', 'description', 'mitigation', 'owner_user_id', 'status', 'metadata']],
            'tender_source' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderSource::class, 'fields' => ['id', 'organization_id', 'code', 'label', 'source_type', 'base_url', 'is_active']],
            'tender_timeline_event' => ['model' => \App\BusinessModules\Features\Tenders\Models\TenderTimelineEvent::class, 'fields' => ['id', 'organization_id', 'tender_id', 'actor_user_id', 'event_type', 'summary', 'metadata', 'created_at']],
            'advance_account_transaction' => ['model' => \App\Models\AdvanceAccountTransaction::class, 'fields' => ['id', 'user_id', 'organization_id', 'project_id', 'type', 'amount', 'description', 'recipient_name', 'document_number', 'document_date', 'balance_after', 'reporting_status', 'reported_at', 'approved_at', 'created_by_user_id', 'approved_by_user_id', 'cost_category_id']],
            'advance_account_setting' => ['model' => \App\Models\AdvanceAccountSetting::class, 'fields' => ['id', 'organization_id', 'max_single_issue_amount', 'report_submission_deadline_days', 'dual_authorization_threshold', 'require_project_for_expense', 'notify_admin_on_overdue_report', 'notify_admin_on_high_value_transaction', 'high_value_notification_threshold', 'notify_user_on_transaction_approval', 'notify_user_on_transaction_rejection', 'send_report_reminder_days_before']],
        ];
    }
}
