<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ProcurementBusinessRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SalesBusinessRagSource;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AssistantSalesBusinessSourcesTest extends TestCase
{
    use UsesAssistantUnitTranslations { setUp as translationsSetUp; tearDown as translationsTearDown; }

    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        $this->translationsSetUp();
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['unit' => new PostgresConnection(null, 'isolated_no_connection', '', ['driver' => 'pgsql'])]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);
        $network = \Mockery::mock(MarketplaceSearchService::class);
        $network->shouldReceive('networkOrganizationIds')->with(17)->andReturn([23, 31]);
        app()->instance(MarketplaceSearchService::class, $network);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        \Mockery::close();
        $this->translationsTearDown();
    }

    public function test_reviewed_business_inventory_has_explicit_standalone_or_parent_projection_coverage(): void
    {
        $expected = [
            \App\Models\Supplier::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorCategory::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorDocument::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorPortfolioItem::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorRating::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorRegion::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferWorkPackage::class,
            \App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory::class,
            \App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardPolicyVersion::class,
            \App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardRow::class,
            \App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardSnapshot::class,
            \App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimate::class,
            \App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateBudgetTransferOperation::class,
            \App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateLineItem::class,
            \App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateSection::class,
            \App\BusinessModules\Features\PresaleEstimates\Models\PresaleEstimateVersion::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalApproval::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalFile::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalLineItem::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalSection::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalSentEvent::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalTemplate::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalTimelineEvent::class,
            \App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion::class,
            \App\BusinessModules\Features\Crm\Models\CrmContactIdentity::class,
            \App\BusinessModules\Features\Crm\Models\CrmContactPoint::class,
            \App\BusinessModules\Features\Crm\Models\CrmConversionOperation::class,
            \App\BusinessModules\Features\Crm\Models\CrmImportBatch::class,
            \App\BusinessModules\Features\Crm\Models\CrmImportRow::class,
            \App\BusinessModules\Features\Crm\Models\CrmMergeEvent::class,
            \App\BusinessModules\Features\Crm\Models\CrmPipeline::class,
            \App\BusinessModules\Features\Crm\Models\CrmPipelineStage::class,
            \App\BusinessModules\Features\Crm\Models\CrmSource::class,
            \App\BusinessModules\Features\Crm\Models\CrmTimelineEvent::class,
            \App\BusinessModules\Features\Procurement\Models\ExternalSupplierContact::class,
            \App\BusinessModules\Features\Procurement\Models\ProcurementApprovalPolicy::class,
            \App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem::class,
            \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptDocument::class,
            \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptInventoryLot::class,
            \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine::class,
            \App\BusinessModules\Features\Procurement\Models\PurchaseReceiptReturn::class,
            \App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine::class,
            \App\BusinessModules\Features\Procurement\Models\SupplierParty::class,
            \App\BusinessModules\Features\Procurement\Models\SupplierProposalIntake::class,
            \App\BusinessModules\Features\Procurement\Models\SupplierProposalLine::class,
            \App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion::class,
            \App\BusinessModules\Features\Procurement\Models\SupplierRequestLine::class,
            \App\BusinessModules\Features\Procurement\Models\SupplierRequestVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Award\Models\ProcurementAwardEvidenceEvent::class,
            \App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardRow::class,
            \App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardSnapshot::class,
            \App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleOwnerExpectationVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleRow::class,
            \App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCycleSnapshot::class,
            \App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementProcessEvent::class,
            \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\PurchaseOrderPromiseVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SentPurchaseOrderLineOwner::class,
            \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyLifecycleEvent::class,
            \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityPolicyVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilityRow::class,
            \App\BusinessModules\Features\Procurement\Reporting\Supply\Models\SupplyReliabilitySnapshot::class,
            \App\BusinessModules\Features\Procurement\Reporting\Award\Models\ProcurementAwardPolicyVersion::class,
            \App\BusinessModules\Features\Procurement\Reporting\Award\Models\ProcurementAwardEvidenceCandidate::class,
        ];
        $actual = [...array_column(Metadata::inventory(), 'model'), ...array_keys(Metadata::parentProjectedModels())];
        self::assertCount(69, $actual);
        self::assertEqualsCanonicalizing($expected, $actual);
        foreach ($actual as $class) { self::assertTrue(is_subclass_of($class, Model::class), $class); }
        foreach (Metadata::sourceClasses() as $class) {
            $source = new $class;
            self::assertNotEmpty($source->entities());
            foreach ($source->entities() as $type => $definition) {
                self::assertSame(Metadata::recordDefinitions()[$type]['source'], $source->sourceType());
                self::assertSame(Metadata::inventory()[$type], $definition);
            }
        }
    }

    public function test_only_reviewed_scalar_fields_are_hydrated_and_labelled(): void
    {
        $labels = Metadata::fieldLabels();
        $entities = Metadata::entityLabels();
        foreach (Metadata::recordDefinitions() as $type => $record) {
            self::assertArrayHasKey($type, $entities);
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $entities[$type]);
            $model = new $record['model'];
            foreach ($record['fields'] as $field) {
                self::assertArrayHasKey($field, $labels, $type.'.'.$field);
                self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $labels[$field]);
                self::assertNotContains($model->getCasts()[$field] ?? '', ['array', 'json', 'object', 'collection'], $type.'.'.$field);
                self::assertDoesNotMatchRegularExpression('/(?:token|password|secret|storage_path|file_path|body_html|payload|_hash|raw_values|normalized_values|duplicate_candidates|settings|mapping)/', $field);
            }
            self::assertSame($record['fields'], Metadata::safeSelectColumns()[$type]);
        }
    }

    public function test_all_declared_parent_graphs_are_finite_and_nonself_cycles_are_not_bypassed(): void
    {
        $records = Metadata::scopeDefinitions();
        $visit = function (string $type, array $seen) use (&$visit, $records): void {
            self::assertNotContains($type, $seen, 'Cycle: '.implode(' -> ', [...$seen, $type]));
            foreach ($records[$type]['parents'] as $parent) {
                self::assertArrayHasKey($parent['type'], $records);
                if ($parent['reference_only'] ?? false) { self::assertSame($type, $parent['type']); continue; }
                $visit($parent['type'], [...$seen, $type]);
            }
        };
        foreach (array_keys($records) as $type) { $visit($type, []); }
    }

    public function test_every_collector_query_has_org_parent_scope_before_the_limit(): void
    {
        foreach (Metadata::recordDefinitions() as $type => $record) {
            $query = SalesBusinessRagSource::scopedQuery($type, 17)->limit(20);
            $sql = $query->toSql();
            self::assertStringEndsWith('limit 20', $sql);
            self::assertStringContainsString('where', $sql, $type);
            if ($type !== 'marketplace_work_category') { self::assertContains(17, $query->getBindings(), $type); }
            self::assertStringNotContainsString('limit 5', $sql);
        }
        self::assertStringContainsString('1 = 0', SalesBusinessRagSource::scopedQuery('purchase_order_item', 0)->toSql());
    }

    public function test_procurement_lines_and_crm_polymorphic_records_keep_real_parent_restrictions(): void
    {
        $query = SalesBusinessRagSource::scopedQuery('purchase_order_item', 17, 29)->limit(20);
        self::assertStringContainsString('"purchase_order_id" in (select "purchase_orders"."id"', $query->toSql());
        self::assertStringContainsString('"project_organization"."is_active"', $query->toSql());
        self::assertContains(29, $query->getBindings());
        $timeline = SalesBusinessRagSource::scopedQuery('crm_timeline_event', 17)->toSql();
        self::assertStringContainsString('1 = 0', $timeline);
        self::assertStringContainsString('"entity_id" in (select "crm_deals"."id"', $timeline);
        self::assertStringContainsString('"crm_deals"."organization_id"', $timeline);
        $merge = SalesBusinessRagSource::scopedQuery('crm_merge_event', 17)->toSql();
        self::assertStringContainsString('"master_id" in (select', $merge);
        self::assertStringNotContainsString('"duplicate_id" in (select', $merge);
    }

    public function test_optional_reporting_parents_keep_null_branch_inside_tenant_and_registered_parent_scope(): void
    {
        foreach (['procurement_cycle_snapshot' => 'policy_version_id', 'supply_reliability_snapshot' => 'policy_version_id', 'supply_lifecycle_event' => 'promise_version_id'] as $type => $column) {
            $record = Metadata::recordDefinitions()[$type];
            self::assertTrue($record['parents'][$column]['nullable']);
            self::assertSame('organization_id', $record['organization_column']);
            $query = SalesBusinessRagSource::scopedQuery($type, 17);
            $table = $query->getModel()->getTable();
            self::assertStringContainsString('"'.$table.'"."'.$column.'" is null or "'.$table.'"."'.$column.'" in (select', $query->toSql());
            self::assertStringContainsString('"'.$table.'"."organization_id" = ?', $query->toSql());
            self::assertContains(17, $query->getBindings());
            self::assertNotEmpty(Metadata::entityPermissions()[$type]);
        }
        self::assertFalse(Metadata::recordDefinitions()['supply_lifecycle_event']['parents']['purchase_order_id']['nullable']);
        self::assertFalse(Metadata::recordDefinitions()['supply_reliability_row']['parents']['promise_version_id']['nullable']);
    }

    public function test_marketplace_has_a_closed_network_and_no_global_profile_offer_bypass(): void
    {
        $query = SalesBusinessRagSource::scopedQuery('marketplace_contractor_profile', 17);
        self::assertStringContainsString('"status" = ?', $query->toSql());
        self::assertStringContainsString('"is_visible_in_marketplace" = ?', $query->toSql());
        self::assertContains(23, $query->getBindings());
        self::assertContains('active', $query->getBindings());
        self::assertArrayNotHasKey('marketplace_contractor_profile', Metadata::globalCatalogEntities());
        self::assertArrayNotHasKey('marketplace_hiring_offer', Metadata::globalCatalogEntities());
        self::assertTrue(Metadata::customOrganizationScopes()['marketplace_contractor_profile']);
        $offer = SalesBusinessRagSource::scopedQuery('marketplace_hiring_offer', 17)->toSql();
        self::assertStringContainsString('"hiring_organization_id" = ?', $offer);
        self::assertStringContainsString('"contractor_organization_id" = ?', $offer);
        self::assertStringContainsString('"projects"."id"', $offer);
    }

    public function test_composite_projections_use_native_parent_ordinal_and_exact_policy_pin(): void
    {
        $projections = Metadata::parentProjectionDefinitions()['procurement_award_evidence_event'];
        self::assertSame('event_id', $projections['award_candidates']['parent_column']);
        self::assertSame('ordinal', $projections['award_candidates']['ordinal_column']);
        self::assertNotContains('id', $projections['award_candidates']['fields']);
        self::assertSame(['finance.view'], $projections['award_candidates']['field_permissions']['total_amount']);
        self::assertArrayHasKey('proposal_id', $projections['award_candidates']['parents']);
        self::assertSame('policy_id', $projections['award_policy']['parent_key']);
        self::assertSame('policy_version', $projections['award_policy']['matches']['version']);
        self::assertSame('policy_hash', $projections['award_policy']['matches']['policy_hash']);
        self::assertArrayNotHasKey('procurement_award_policy_version', Metadata::entityDefinitions());
        self::assertArrayNotHasKey('procurement_award_evidence_candidate', Metadata::entityDefinitions());
    }

    public function test_inactive_presale_workflow_has_finite_metadata_but_no_invented_live_or_indexed_availability(): void
    {
        $availability = Metadata::availabilityDefinitions();
        self::assertCount(5, $availability);
        foreach ($availability as $type => $value) {
            self::assertSame('unavailable', $value['availability']);
            self::assertSame('no_current_canonical_read_entitlement', $value['reason_code']);
            self::assertNull(Metadata::recordDefinitions()[$type]['module']);
            self::assertFalse(Metadata::retrievalCoverageDefinitions()[$type]['indexed']);
            self::assertSame('unavailable', Metadata::retrievalCoverageDefinitions()[$type]['mode']);
            self::assertNotEmpty(Metadata::inventory()[$type]['fields']);
            self::assertArrayHasKey($type, Metadata::entityDefinitions());
        }
        self::assertArrayNotHasKey('presale_estimates', Metadata::domainGates());
        self::assertNotContains('presale_estimates', array_column(Metadata::domainDefinitions(), 'domain'));
        $source = new \App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\PresaleBusinessRagSource;
        self::assertFalse($source->enabled());
        self::assertSame([], [...$source->collectEntity(17, 'presale_estimate', '11111111-1111-4111-8111-111111111111')]);
        self::assertSame([], [...$source->collectForOrganization(17)]);
    }

    public function test_raw_money_is_exact_and_private_payloads_do_not_enter_chunks(): void
    {
        $record = Metadata::recordDefinitions()['purchase_order_item'];
        $model = new $record['model'];
        $model->setRawAttributes(['id' => 9, 'organization_id' => 17, 'unit_price' => '123456789.1234', 'quantity' => '2.50000000', 'total_price' => '308641972.81', 'payload' => ['secret' => 'hidden'] ], true);
        $chunk = (new ReflectionMethod(SalesBusinessRagSource::class, 'chunk'))->invoke(new ProcurementBusinessRagSource, $model, 'purchase_order_item', $record['fields'], 17);
        self::assertSame('123456789.1234', $chunk->metadata['unit_price']);
        self::assertSame('2.50000000', $chunk->metadata['quantity']);
        self::assertStringNotContainsString('hidden', $chunk->content);
        self::assertSame(9, $chunk->entityId);
    }

    public function test_financial_fields_and_actual_read_permissions_do_not_become_domain_all_permission_union(): void
    {
        foreach (Metadata::domainDefinitions() as $definition) {
            self::assertSame([], $definition->permissions);
            foreach (array_intersect($definition->fields, Metadata::factFieldGroups()['money']) as $field) {
                self::assertNotEmpty($definition->fieldPermissions[$field]);
            }
        }
        self::assertSame(['presale_estimates.view'], Metadata::entityPermissions()['presale_estimate']);
        self::assertSame(['procurement.dashboard.view'], Metadata::entityPermissions()['procurement_cycle_row']);
        self::assertNotContains('rating_score', Metadata::factFieldGroups()['money']);
        self::assertNotContains('quantity', Metadata::factFieldGroups()['money']);
    }
}
