<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\AdvanceAccountingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\BudgetingRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ScopedFinanceTenderRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\TenderRagSource;
use App\Models\AdvanceAccountTransaction;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantFinanceTenderSourcesTest extends TestCase
{
    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['unit' => new PostgresConnection(null, 'isolated_no_connection', '', ['driver' => 'pgsql'])]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
    }

    public function test_every_concrete_budgeting_tender_model_is_registered_or_explicitly_excluded(): void
    {
        $registered = array_column(AssistantFinanceTenderMetadata::inventory(), 'model');
        $excluded = AssistantFinanceTenderMetadata::excludedModels();
        $root = dirname(__DIR__, 3).'/app/BusinessModules/Features/';
        foreach (['Budgeting', 'Tenders'] as $module) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.$module));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php' || basename($file->getPath()) !== 'Models') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                preg_match('/namespace ([^;]+);/', $source, $namespace);
                $class = $namespace[1].'\\'.$file->getBasename('.php');
                if ((new ReflectionClass($class))->isAbstract()) {
                    continue;
                }
                self::assertTrue(in_array($class, $registered, true) || isset($excluded[$class]), 'Unclassified business model: '.$class);
            }
        }
    }

    public function test_secrets_and_internal_ingestion_state_are_not_business_content(): void
    {
        $inventory = AssistantFinanceTenderMetadata::inventory();
        foreach ($inventory as $definition) {
            foreach (['lease_token', 'active_lock', 'ip_address', 'user_agent', 'accounting_data', 'attachment_ids'] as $field) {
                self::assertNotContains($field, $definition['fields']);
            }
        }
        self::assertNotContains('settings', $inventory['tender_source']['fields']);
        self::assertNotContains('external_code', $inventory['advance_account_transaction']['fields']);
        self::assertArrayNotHasKey('portfolio_liquidity_backfill_checkpoint', $inventory);
    }

    public function test_budget_amount_query_proves_parent_organization_and_project_before_limit(): void
    {
        $query = ScopedFinanceTenderRagSource::scopedQuery('budget_amount', 17, 29)->limit(20);
        $sql = $query->toSql();
        self::assertStringContainsString('"budget_line_id" in (select "budget_lines"."id"', $sql);
        self::assertStringContainsString('"budget_versions"."organization_id" = ?', $sql);
        self::assertStringContainsString('"budget_lines"."project_id" = ?', $sql);
        self::assertStringContainsString('"project_organization"."is_active" = ?', $sql);
        self::assertStringContainsString('"budget_versions"."deleted_at" is null', $sql);
        self::assertStringEndsWith('limit 20', $sql);
        self::assertContains(17, $query->getBindings());
        self::assertContains(29, $query->getBindings());
    }

    public function test_tender_file_query_requires_live_tender_and_same_organization_linked_entities(): void
    {
        $query = ScopedFinanceTenderRagSource::scopedQuery('tender_file', 17, 29);
        $sql = $query->toSql();
        self::assertStringContainsString('"tender_files"."tender_id" in (select "tenders"."id"', $sql);
        self::assertStringContainsString('"tenders"."organization_id" = ?', $sql);
        self::assertStringContainsString('"tenders"."project_id" = ?', $sql);
        self::assertStringContainsString('"tenders"."deleted_at" is null', $sql);
        self::assertStringContainsString('"crm_companies"."organization_id" = ?', $sql);
        self::assertStringContainsString('"contracts"."organization_id" = ?', $sql);
        $reminder = ScopedFinanceTenderRagSource::scopedQuery('tender_deadline_reminder', 17)->toSql();
        self::assertStringContainsString('"tender_deadlines"."tender_id" = "tender_deadline_reminders"."tender_id"', $reminder);
    }

    public function test_watermark_uses_actual_non_primary_close_key_and_no_parentless_fallback(): void
    {
        $sql = ScopedFinanceTenderRagSource::scopedQuery('budgeting_report_source_watermark_record', 17)->toSql();
        self::assertStringContainsString('"close_id" in (select "budgeting_report_source_closes"."close_id"', $sql);
        self::assertStringContainsString('"budgeting_report_source_closes"."organization_id" = ?', $sql);
        self::assertStringContainsString('1 = 0', ScopedFinanceTenderRagSource::scopedQuery('budget_period', 17, 29)->toSql());
        self::assertStringContainsString('1 = 0', ScopedFinanceTenderRagSource::scopedQuery('budget_amount', 0)->toSql());
    }

    public function test_inherited_project_identity_is_selected_in_sql_without_per_row_parent_reads(): void
    {
        $source = new BudgetingRagSource;
        $query = (new ReflectionMethod(ScopedFinanceTenderRagSource::class, 'collectionQuery'))->invoke($source, 'budget_amount', 17, 29);
        $sql = $query->toSql();
        self::assertStringContainsString('as "assistant_project_id"', $sql);
        self::assertStringContainsString('"budget_lines"."id" = "budget_amounts"."budget_line_id"', $sql);
        $model = new \App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
        $model->setRawAttributes(['id' => 41, 'budget_line_id' => 42, 'plan_amount' => '123.27', 'assistant_project_id' => 29], true);
        $chunk = (new ReflectionMethod(ScopedFinanceTenderRagSource::class, 'chunk'))->invoke($source, $model, 'budget_amount',
            AssistantFinanceTenderMetadata::inventory()['budget_amount']['fields'], 17);
        self::assertSame(29, $chunk->projectId);
        self::assertArrayNotHasKey('assistant_project_id', $chunk->metadata);
    }

    public function test_content_preserves_decimal_precision_and_does_not_include_unlisted_attributes(): void
    {
        $model = new AdvanceAccountTransaction;
        $model->setRawAttributes(['id' => 41, 'organization_id' => 17, 'project_id' => 29, 'amount' => '9007199254740993.27',
            'balance_after' => '10000000000000000.01', 'accounting_data' => '{"secret":"PRIVATE"}', 'external_code' => 'PRIVATE'], true);
        $source = new AdvanceAccountingRagSource;
        $chunk = (new ReflectionMethod(ScopedFinanceTenderRagSource::class, 'chunk'))->invoke($source, $model,
            'advance_account_transaction', AssistantFinanceTenderMetadata::inventory()['advance_account_transaction']['fields'], 17);
        self::assertSame('9007199254740993.27', $chunk->metadata['amount']);
        self::assertSame('10000000000000000.01', $chunk->metadata['balance_after']);
        self::assertSame(29, $chunk->projectId);
        self::assertStringNotContainsString('PRIVATE', $chunk->content);
        $model->setRawAttributes(['id' => 41, 'organization_id' => 17, 'amount' => 1.25], true);
        $unsupported = (new ReflectionMethod(ScopedFinanceTenderRagSource::class, 'chunk'))->invoke($source, $model,
            'advance_account_transaction', AssistantFinanceTenderMetadata::inventory()['advance_account_transaction']['fields'], 17);
        self::assertArrayNotHasKey('amount', $unsupported->metadata, 'A binary float cannot be published as exact financial evidence.');
    }

    public function test_searchable_chunk_does_not_embed_internal_epm_cache_or_mixed_scope_money_even_with_private_field_input(): void
    {
        $definition = AssistantFinanceTenderMetadata::inventory()['epm_data_mart_snapshot'];
        $model = new $definition['model'];
        $model->setRawAttributes(['id' => '11111111-1111-4111-8111-111111111111', 'organization_id' => 17,
            'status' => 'ready', 'payload' => '{"secret":"PRIVATE_OPAQUE","total_amount":"123456.78"}',
            'source_refs' => '[{"private":"PRIVATE_REFERENCE"}]', 'source_hash' => str_repeat('a', 64),
            'filters' => '{"private":"PRIVATE_FILTER"}', 'generated_at' => '2026-09-29 10:00:00'], true);
        $method = new ReflectionMethod(ScopedFinanceTenderRagSource::class, 'chunk');
        $chunk = $method->invoke(new BudgetingRagSource, $model, 'epm_data_mart_snapshot', $definition['fields'], 17);
        self::assertSame('ready', $chunk->metadata['status']);
        $schema = \App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema::class;
        self::assertSame($schema::revision('epm_data_mart_snapshot'), $chunk->metadata[$schema::FIELD]);
        self::assertStringNotContainsString($schema::FIELD, $chunk->content);
        self::assertStringNotContainsString($chunk->metadata[$schema::FIELD], $chunk->content);
        self::assertTrue($schema::allowsSource(['entity_type' => 'epm_data_mart_snapshot', 'metadata' => $chunk->metadata]));
        self::assertFalse($schema::allowsSource(['entity_type' => 'epm_data_mart_snapshot', 'metadata' => []]));
        self::assertSame($model->getKey(), $chunk->entityId);
        foreach (['payload', 'source_refs', 'source_hash', 'filters'] as $field) { self::assertArrayNotHasKey($field, $chunk->metadata); }
        foreach (['PRIVATE_OPAQUE', 'PRIVATE_REFERENCE', 'PRIVATE_FILTER', '123456.78', str_repeat('a', 64)] as $secret) { self::assertStringNotContainsString($secret, $chunk->content); }
    }

    public function test_granular_permissions_and_aggregate_boundaries_are_declared(): void
    {
        $permissions = AssistantFinanceTenderMetadata::entityPermissions();
        self::assertContains('budgeting.management_pnl.view', $permissions['management_pnl_record']);
        self::assertContains('budgeting.wip_forecast.view_sensitive_costs', $permissions['wip_forecast_line']);
        self::assertContains('budgeting.wip_forecast.view_audit', $permissions['wip_forecast_audit_event']);
        self::assertContains('advance_settings.view', $permissions['advance_account_setting']);
        self::assertContains('tenders.amounts.view', AssistantFinanceTenderMetadata::sourcePermissions()['tenders']);
        $aggregates = AssistantFinanceTenderMetadata::organizationAggregates();
        self::assertSame('project_id', $aggregates['wip_forecast_version']);
        self::assertTrue($aggregates['management_pnl_snapshot']);
        self::assertArrayNotHasKey('budget_version', $aggregates);
        self::assertArrayNotHasKey('budget_amount', $aggregates);
        self::assertTrue(AssistantFinanceTenderMetadata::attachmentDefinitions()['tender_file']['inherits_parent_permissions']);
    }

    public function test_source_adapters_partition_inventory_without_loss_and_schema_accepts_existing_id_shapes(): void
    {
        $entities = [];
        foreach ([new BudgetingRagSource, new TenderRagSource, new AdvanceAccountingRagSource] as $source) {
            foreach ($source->entities() as $type => $definition) {
                self::assertSame($source->sourceType(), AssistantFinanceTenderMetadata::entityDefinitions()[$type][0]);
                self::assertArrayNotHasKey($type, $entities);
                $entities[$type] = $definition;
            }
            self::assertSame([], $source->collectEntity(17, 'unsupported', 1));
        }
        self::assertCount(count(AssistantFinanceTenderMetadata::inventory()), $entities);
        foreach (AssistantFinanceTenderMetadata::domainDefinitions() as $definition) {
            self::assertSame([], $definition->permissions, 'Domain discovery ANY permissions must not become mandatory ALL read permissions.');
            self::assertFalse($definition->schemas['read']['additionalProperties']);
            self::assertSame(['integer', 'string'], $definition->schemas['read']['properties']['id']['type']);
            self::assertSame(20, $definition->schemas['search']['properties']['limit']['maximum']);
            self::assertContains('entity_type', $definition->schemas['read']['required']);
        }
    }

    public function test_public_source_catalogs_and_every_declared_field_have_russian_labels(): void
    {
        $sql = ScopedFinanceTenderRagSource::scopedQuery('tender_source', 17)->toSql();
        self::assertStringContainsString('"tender_sources"."organization_id" is null', $sql);
        $labels = require dirname(__DIR__, 3).'/lang/ru/ai_assistant_finance_tenders.php';
        foreach (AssistantFinanceTenderMetadata::structuredFields() as $field) {
            self::assertArrayHasKey($field, $labels['fields'], 'Missing Russian field label: '.$field);
        }
        foreach (array_keys(AssistantFinanceTenderMetadata::inventory()) as $type) {
            self::assertArrayHasKey($type, $labels['entities']);
        }
        $groups = AssistantFinanceTenderMetadata::factFieldGroups();
        self::assertNotContains('percent_complete', $groups['money']);
        self::assertNotContains('score', $groups['money']);
        self::assertContains('plan_amount', $groups['money']);
        self::assertContains('winner_amount', $groups['money']);
        self::assertContains('due_at', $groups['date']);
        self::assertTrue(AssistantFinanceTenderMetadata::attachmentDefinitions()['tender_file']['metadata_only']);
        self::assertNotContains('stored_path', AssistantFinanceTenderMetadata::inventory()['tender_file']['fields']);
    }
}
