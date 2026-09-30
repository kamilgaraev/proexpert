<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantCoreBusinessMetadataTest extends TestCase
{
    public function test_core_domains_explicitly_delegate_to_existing_entity_permissions(): void
    {
        self::assertSame(array_fill_keys(array_keys(Metadata::domainGates()), true), Metadata::domainEntityPermissionGates());
        foreach (Metadata::records() as $type => $record) {
            self::assertTrue(Metadata::domainEntityPermissionGates()[$record['domain']]);
            self::assertSame($record['permissions'], Metadata::entityPermissions()[$type]);
            self::assertSame($record['module'], Metadata::domainGates()[$record['domain']][0]);
        }
    }
    private const UNIVERSE = [
        'App\\BusinessModules\\Core\\Payments\\Models\\CounterpartyAccount',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentApproval',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentApprovalRule',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentAuditLog',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentDocumentEstimateSplit',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentDocumentSplit',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentSchedule',
        'App\\BusinessModules\\Core\\Payments\\Models\\PaymentTransaction',
        'App\\BusinessModules\\Features\\BudgetEstimates\\Models\\WorkVolumeAcceptanceMapping',
        'App\\BusinessModules\\Features\\BudgetEstimates\\Models\\WorkVolumeAcceptedAllocation',
        'App\\BusinessModules\\Features\\BudgetEstimates\\Models\\WorkVolumeStatement',
        'App\\BusinessModules\\Features\\BudgetEstimates\\Models\\WorkVolumeStatementCoverage',
        'App\\BusinessModules\\Features\\BudgetEstimates\\Models\\WorkVolumeStatementImport',
        'App\\BusinessModules\\Features\\BudgetEstimates\\Models\\WorkVolumeStatementLine',
        'App\\BusinessModules\\Features\\ContractManagement\\Reporting\\Models\\ContractSettlementExposureRecord',
        'App\\BusinessModules\\Features\\ContractManagement\\Reporting\\Models\\ContractSettlementExposureSnapshot',
        'App\\BusinessModules\\Features\\ContractManagement\\Reporting\\Models\\ContractSettlementOwnerHistoryCheckpoint',
        'App\\BusinessModules\\Features\\ContractManagement\\Reporting\\Models\\ContractSettlementOwnerVersion',
        'App\\BusinessModules\\Features\\ContractManagement\\Reporting\\Models\\ContractSettlementSourceFact',
        'App\\BusinessModules\\Features\\KnowledgeHub\\Models\\KnowledgeArticleFeedback',
        'App\\BusinessModules\\Features\\KnowledgeHub\\Models\\KnowledgeCategory',
        'App\\BusinessModules\\Features\\NormativeReferences\\Models\\NormativeResource',
        'App\\BusinessModules\\Features\\Notifications\\Models\\Notification',
        'App\\BusinessModules\\Features\\Notifications\\Models\\NotificationTemplate',
        'App\\BusinessModules\\Features\\TimeTracking\\Reporting\\Models\\ApprovedTimeEntryReportingFact',
        'App\\Models\\ActFieldConfirmation',
        'App\\Models\\ActingPolicy',
        'App\\Models\\BalanceTransaction',
        'App\\Models\\Blog\\BlogArticle',
        'App\\Models\\Blog\\BlogArticleRevision',
        'App\\Models\\Blog\\BlogCategory',
        'App\\Models\\Blog\\BlogComment',
        'App\\Models\\Blog\\BlogMediaAsset',
        'App\\Models\\Blog\\BlogSeoSettings',
        'App\\Models\\Blog\\BlogTag',
        'App\\Models\\CommercialContourChange',
        'App\\Models\\CommercialOrder',
        'App\\Models\\CommercialPayment',
        'App\\Models\\CommercialRefund',
        'App\\Models\\CompletedWorkCorrection',
        'App\\Models\\CompletedWorkHistoryTransformation',
        'App\\Models\\CompletedWorkMaterial',
        'App\\Models\\ConstructionJournal',
        'App\\Models\\ConstructionJournalEntry',
        'App\\Models\\ContactForm',
        'App\\Models\\ContractAllocationHistory',
        'App\\Models\\ContractCurrentState',
        'App\\Models\\ContractDossierSource',
        'App\\Models\\ContractEstimateItem',
        'App\\Models\\Contractor',
        'App\\Models\\ContractOrganizationView',
        'App\\Models\\ContractorInvitation',
        'App\\Models\\ContractorReferralReward',
        'App\\Models\\ContractorVerification',
        'App\\Models\\ContractParty',
        'App\\Models\\ContractPeriodCertificate',
        'App\\Models\\ContractPeriodCertificateAct',
        'App\\Models\\ContractProjectAllocation',
        'App\\Models\\ContractStateEvent',
        'App\\Models\\ContractSupplementaryDocument',
        'App\\Models\\CostCategory',
        'App\\Models\\Counterparty',
        'App\\Models\\CustomerPortalComment',
        'App\\Models\\CustomerRequest',
        'App\\Models\\EstimateChangeLog',
        'App\\Models\\EstimateFinanceAllocation',
        'App\\Models\\EstimateImportHistory',
        'App\\Models\\EstimateItemTotal',
        'App\\Models\\EstimateItemWork',
        'App\\Models\\EstimateLibrary',
        'App\\Models\\EstimateLibraryItem',
        'App\\Models\\EstimateLibraryItemPosition',
        'App\\Models\\EstimateLibraryUsage',
        'App\\Models\\EstimatePositionCatalog',
        'App\\Models\\EstimatePositionCatalogCategory',
        'App\\Models\\EstimatePositionPriceHistory',
        'App\\Models\\EstimateRevisionOperation',
        'App\\Models\\EstimateSnapshot',
        'App\\Models\\EstimateTemplate',
        'App\\Models\\EstimateVersion',
        'App\\Models\\GeneralJournalDocumentVersion',
        'App\\Models\\ImportSession',
        'App\\Models\\JournalEntryApprovalEvent',
        'App\\Models\\JournalEquipment',
        'App\\Models\\JournalExport',
        'App\\Models\\JournalMaterial',
        'App\\Models\\JournalWorker',
        'App\\Models\\JournalWorkVolume',
        'App\\Models\\LaborResource',
        'App\\Models\\Machinery',
        'App\\Models\\MaterialConsumptionFact',
        'App\\Models\\MaterialConsumptionRate',
        'App\\Models\\MaterialConsumptionStatement',
        'App\\Models\\Models\\Log\\WorkCompletionLog',
        'App\\Models\\NormativeBaseType',
        'App\\Models\\NormativeCollection',
        'App\\Models\\NormativeImportLog',
        'App\\Models\\NormativeRate',
        'App\\Models\\NormativeRateResource',
        'App\\Models\\NormativeSection',
        'App\\Models\\OneCBase',
        'App\\Models\\OneCExchangeConflict',
        'App\\Models\\OneCExchangeConflictEvent',
        'App\\Models\\OneCExchangeMapping',
        'App\\Models\\OneCExchangeMessage',
        'App\\Models\\OneCExchangeOperation',
        'App\\Models\\OneCIntegrationProfile',
        'App\\Models\\Organization',
        'App\\Models\\OrganizationBalance',
        'App\\Models\\OrganizationCommercialAccount',
        'App\\Models\\OrganizationDispute',
        'App\\Models\\OrganizationGroup',
        'App\\Models\\OrganizationModuleActivation',
        'App\\Models\\OrganizationPackageSubscription',
        'App\\Models\\OrganizationResourceAllocation',
        'App\\Models\\PerformanceActCompletedWork',
        'App\\Models\\PerformanceActLine',
        'App\\Models\\PerformanceActReversal',
        'App\\Models\\PersonalFile',
        'App\\Models\\PriceIndex',
        'App\\Models\\ProjectAddress',
        'App\\Models\\ProjectOrganization',
        'App\\Models\\ProjectParticipantInvitation',
        'App\\Models\\PtoWorkspaceTask',
        'App\\Models\\RateCoefficient',
        'App\\Models\\RateCoefficientApplication',
        'App\\Models\\RegionalCoefficient',
        'App\\Models\\ReportFile',
        'App\\Models\\ScheduleTaskInterval',
        'App\\Models\\Specification',
        'App\\Models\\SupplementaryAgreement',
        'App\\Models\\Supplier',
        'App\\Models\\TaskDependency',
        'App\\Models\\TaskMilestone',
        'App\\Models\\TaskResource',
        'App\\Models\\UserInvitation',
        'App\\Models\\WorkTypeMatchingDictionary',
        'App\\Models\\WorkTypeMaterial',
    ];

    public function test_every_finite_business_model_is_declared_or_has_a_specific_exclusion(): void
    {
        self::assertCount(138, self::UNIVERSE);
        $models = array_column(Metadata::inventory(), 'model');
        $excluded = Metadata::excludedModels();
        self::assertCount(count($models), array_unique($models));
        self::assertSame([], array_values(array_diff([...$models, ...array_keys($excluded)], self::UNIVERSE)));
        self::assertSame([], array_values(array_intersect($models, array_keys($excluded))));
        foreach (self::UNIVERSE as $class) {
            self::assertTrue(in_array($class, $models, true) || isset($excluded[$class]), $class);
        }
        foreach ($excluded as $class => $reason) {
            self::assertTrue(is_subclass_of($class, Model::class), $class);
            self::assertIsString($reason);
            self::assertGreaterThanOrEqual(12, strlen($reason), $class);
            self::assertNotContains(strtolower(trim($reason)), ['excluded', 'technical', 'unsupported', 'out_of_scope']);
        }
    }

    public function test_read_and_rag_allowlists_cannot_export_secrets_raw_payloads_or_configuration(): void
    {
        foreach (Metadata::inventory() as $type => $record) {
            self::assertTrue(is_subclass_of($record['model'], Model::class), $type);
            self::assertSame($record['table'], (new $record['model'])->getTable(), $type);
            if ($record['permissions'] === []) {
                self::assertSame('core_knowledge', $record['domain'], $type);
                self::assertTrue($record['global'] || ($record['actor_column'] !== null && $record['parents'] !== []), $type);
            }
            self::assertContains((new $record['model'])->getKeyName(), $record['fields'], $type);
            self::assertSame([], array_diff($record['rag_fields'], $record['fields']), $type);
            foreach ($record['fields'] as $field) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $field, $type);
                self::assertDoesNotMatchRegularExpression('/(?:password|secret|credential|token|api_key|payload|metadata|config|storage_path|private_key|raw_body|canonical_url|source_canonical|content_canonical)/i', $field, $type);
            }
            foreach (['organization_column', 'project_column', 'actor_column'] as $key) {
                self::assertTrue($record[$key] === null || is_string($record[$key]), $type.'.'.$key);
            }
            if ($record['actor_column'] !== null) { self::assertFalse($record['indexed'], $type); }
            self::assertNotEmpty($record['domain'], $type);
            if ($record['module'] === '') { self::assertContains($record['domain'], ['core_knowledge', 'core_commercial'], $type); }
            self::assertContains($record['source'], ['core_business', 'core_business_money'], $type);
        }
        self::assertSame(Metadata::inventory(), Metadata::records());
        self::assertSame(Metadata::inventory(), Metadata::recordDefinitions());
    }

    public function test_parent_graph_has_known_targets_and_only_flagged_reference_cycles(): void
    {
        $records = Metadata::inventory();
        $policy = new ReflectionClass(AssistantDataAccessPolicy::class);
        $known = array_keys($policy->getMethod('entities')->invoke($policy->newInstanceWithoutConstructor()));
        $seen = [];
        $active = [];
        $visit = function (string $type) use (&$visit, &$seen, &$active, $records, $known): void {
            if (isset($seen[$type])) { return; }
            self::assertArrayNotHasKey($type, $active, 'Unflagged security parent cycle at '.$type);
            $active[$type] = true;
            foreach ($records[$type]['parents'] as $column => $parent) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $column);
                self::assertIsBool($parent['nullable']);
                $target = $parent['type'];
                self::assertTrue(isset($records[$target]) || in_array($target, $known, true), $type.' -> '.$target);
                if (($parent['reference_only'] ?? false) === true) { continue; }
                if (isset($records[$target])) { $visit($target); }
            }
            unset($active[$type]);
            $seen[$type] = true;
        };
        foreach (array_keys($records) as $type) { $visit($type); }
        $agreement = $records['core_supplementary_agreement'];
        self::assertSame('contract', $agreement['parents']['contract_id']['type']);
        self::assertContains('change_amount', $agreement['fields']);
        self::assertSame('core_business_money', $agreement['source']);
        foreach (Metadata::sourceClasses() as $sourceClass) {
            self::assertTrue(is_subclass_of($sourceClass, RagSourceCollectorInterface::class), $sourceClass);
        }
    }

    public function test_entity_labels_field_labels_and_financial_groups_match_the_safe_records(): void
    {
        $labels = Metadata::entityLabels();
        $fieldLabels = Metadata::fieldLabels();
        $fields = Metadata::structuredFields();
        $definitions = Metadata::entityDefinitions();
        foreach (Metadata::inventory() as $type => $record) {
            self::assertNotEmpty($labels[$type], $type);
            self::assertSame([$record['source'], $record['model'], $record['domain']], $definitions[$type]);
            foreach ($record['fields'] as $field) {
                self::assertNotEmpty($fieldLabels[$field], $type.'.'.$field);
                self::assertContains($field, $fields);
            }
            if (array_intersect($record['rag_fields'], Metadata::moneyFields()) !== []) {
                self::assertSame('core_business_money', $record['source'], $type);
            }
        }
        self::assertContains('change_amount', Metadata::factFieldGroups()['money']);
        self::assertSame([], array_diff(Metadata::moneyFields(), $fields));
        self::assertSame(['finance.view'], Metadata::sourcePermissions()['core_business_money']);
        self::assertSame('core_business_money', Metadata::inventory()['core_estimate_item_total']['source']);
        foreach (Metadata::domainDefinitions() as $domain) {
            foreach (array_intersect($domain->fields, Metadata::moneyFields()) as $field) {
                self::assertContains('finance.view', (array) $domain->fieldPermissions[$field]);
            }
            self::assertSame([], array_intersect(['create', 'update', 'delete'], $domain->operations));
        }
    }

    public function test_private_live_only_records_are_never_collected_without_current_actor_scope(): void
    {
        $coverage = Metadata::retrievalCoverageDefinitions();
        $private = 0;
        foreach (Metadata::inventory() as $type => $record) {
            if ($record['indexed']) { continue; }
            $private++;
            self::assertSame('live_only', $coverage[$type]['mode'], $type);
            self::assertNotEmpty($coverage[$type]['reason'], $type);
            foreach (Metadata::sourceClasses() as $sourceClass) {
                $source = new $sourceClass();
                self::assertArrayNotHasKey($type, $source->entities(), $type);
                self::assertSame([], [...$source->collectEntity(1, $type, 1)], $type);
            }
        }
        self::assertGreaterThan(0, $private);
        self::assertFalse(Metadata::inventory()['core_customer_portal_comment']['indexed']);
        self::assertSame('author_user_id', Metadata::actorColumns()['core_customer_portal_comment']);
        self::assertFalse(Metadata::inventory()['core_specification']['indexed']);
    }
}
