<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\BudgetingRagSource;
use PHPUnit\Framework\TestCase;

final class AssistantFinanceTenderPublicFieldsTest extends TestCase
{
    public function test_budgeting_allowlists_match_existing_actor_columns_and_preserve_version_creator(): void
    {
        foreach (['budget_article', 'budget_article_mapping', 'budget_line', 'budget_period', 'budget_scenario', 'responsibility_center', 'budget_version'] as $type) {
            self::assertNotContains('updated_by', Metadata::inventory()[$type]['fields']);
            self::assertNotContains('updated_by', Metadata::safeSelectColumns()[$type]);
            self::assertSame(Metadata::publicFields()[$type], (new BudgetingRagSource)->entities()[$type]['fields']);
            if ($type !== 'budget_version') {
                self::assertNotContains('created_by', Metadata::inventory()[$type]['fields']);
                self::assertNotContains('created_by', Metadata::safeSelectColumns()[$type]);
            }
        }
        self::assertContains('created_by', Metadata::inventory()['budget_version']['fields']);
        self::assertContains('created_by', Metadata::safeSelectColumns()['budget_version']);
        self::assertContains('updated_at', Metadata::versionColumns()['budget_version']);
        self::assertSame(['budgeting.budgets.view'], Metadata::entityPermissions()['budget_version']);
        self::assertSame('budget_period', Metadata::parentColumns()['budget_version']['budget_period_id']['type']);
    }
    public function test_generic_catalog_and_read_select_do_not_publish_nested_cache_or_private_source_proof(): void
    {
        $excluded = [
            'budget_import_row' => ['raw_payload'],
            'epm_data_mart_snapshot' => ['payload', 'filters', 'freshness', 'source_refs', 'source_hash'],
            'epm_data_mart_aggregate' => ['dimensions', 'metrics', 'source_refs', 'source_hash'],
            'epm_data_mart_recalculation_run' => ['filters', 'source_refs', 'error_summary', 'source_hash'],
            'budgeting_portfolio_snapshot' => ['totals', 'watermarks', 'source_refs'],
            'portfolio_liquidity_source_version' => ['payload'],
            'project_control_baseline_version' => ['source_payload'],
            'project_control_row' => ['payload', 'source_refs'],
            'project_control_snapshot' => ['totals', 'watermarks', 'row_schema', 'source_refs'],
            'project_finance_snapshot' => ['totals', 'source_refs'],
            'management_pnl_snapshot' => ['component_snapshots', 'totals', 'warnings'],
            'wip_forecast_version' => ['source_snapshot', 'source_snapshot_hash', 'summary', 'formulas', 'source_coverage', 'freshness', 'actions', 'meta'],
        ];
        foreach ($excluded as $type => $fields) {
            foreach ($fields as $field) {
                self::assertNotContains($field, Metadata::publicFields()[$type], $type.'.'.$field);
                self::assertNotContains($field, Metadata::safeSelectColumns()[$type]);
                self::assertNotContains($field, Metadata::fields()[$type]);
            }
        }
        foreach (Metadata::domainDefinitions() as $domain) {
            foreach (['payload', 'source_refs', 'source_hash', 'source_manifest', 'source_row_refs', 'source_payload', 'source_snapshot'] as $field) {
                self::assertNotContains($field, $domain->fields);
                self::assertNotContains($field, $domain->schemas['read']['properties']['fields']['items']['enum']);
                self::assertNotContains($field, $domain->schemas['search']['properties']['fields']['items']['enum']);
            }
        }
    }

    public function test_mixed_scope_cache_money_is_not_authorized_by_one_generic_report_permission(): void
    {
        foreach (['epm_data_mart_aggregate', 'epm_data_mart_snapshot', 'budgeting_portfolio_snapshot',
            'project_portfolio_health_projection', 'portfolio_liquidity_projection', 'portfolio_liquidity_source_version',
            'project_control_row', 'project_control_snapshot', 'project_finance_row', 'project_finance_snapshot'] as $type) {
            self::assertSame([], array_values(array_intersect(Metadata::publicFields()[$type], Metadata::numericFields())), $type);
            self::assertContains('id', Metadata::publicFields()[$type]);
        }
        self::assertContains('plan_amount', Metadata::publicFields()['budget_amount']);
        self::assertContains('initial_max_price', Metadata::publicFields()['tender']);
        self::assertContains('balance_after', Metadata::publicFields()['advance_account_transaction']);
        self::assertContains('status', Metadata::publicFields()['epm_data_mart_snapshot']);
        self::assertContains('normalized_payload', Metadata::publicFields()['budget_import_row']);
    }

    public function test_internal_inventory_remains_available_but_generic_rag_uses_the_curated_projection(): void
    {
        self::assertContains('payload', Metadata::inventory()['epm_data_mart_snapshot']['fields']);
        self::assertContains('source_refs', Metadata::inventory()['epm_data_mart_snapshot']['fields']);
        self::assertContains('source_hash', Metadata::inventory()['epm_data_mart_snapshot']['fields']);
        self::assertSame(array_keys(Metadata::inventory()), array_keys(Metadata::publicInventory()));
        foreach ((new BudgetingRagSource)->entities() as $type => $definition) {
            self::assertSame(Metadata::publicFields()[$type], $definition['fields']);
        }
        self::assertContains('generated_at', Metadata::versionColumns()['epm_data_mart_snapshot']);
        self::assertSame('epm_data_mart_snapshot', Metadata::parentColumns()['epm_data_mart_aggregate']['snapshot_id']['type']);
    }
}
