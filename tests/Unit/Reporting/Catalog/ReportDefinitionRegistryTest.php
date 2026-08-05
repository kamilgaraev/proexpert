<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ManifestReportCatalogMetadataRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ManifestReportSchedulingCapabilityRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\OfficialDocumentDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\PublishedReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use LogicException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class ReportDefinitionRegistryTest extends TestCase
{
    public function test_candidate_and_published_registries_return_nominal_wrappers(): void
    {
        $candidate = $this->candidateRegistry()->candidate('project_portfolio_health');
        $published = $this->publishedRegistry()->published('holding_performance');

        self::assertInstanceOf(CandidateReportDefinition::class, $candidate);
        self::assertInstanceOf(PublishedReportDefinition::class, $published);
        self::assertInstanceOf(ReportDefinition::class, $candidate->payload());
        self::assertInstanceOf(ReportDefinition::class, $published->payload());
        self::assertSame('project_portfolio_health', $candidate->code);
        self::assertSame('holding_performance', $published->code);
    }

    public function test_published_lookup_never_exposes_candidate_blocked_or_unknown_payload(): void
    {
        $manifest = $this->manifest();
        $definitions = $manifest->definitions;
        $definitions[2]['readiness']['publication'] = 'blocked';
        $registry = new PublishedReportDefinitionRegistry(
            new LoadedReportManifest(
                $manifest->catalog,
                $manifest->contractVersion,
                $manifest->bytesHash,
                $definitions,
            ),
            $this->factory(),
        );

        foreach (['project_portfolio_health', 'intercompany_contract_flows', 'unknown_report'] as $code) {
            try {
                $registry->published($code);
                self::fail($code);
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
                self::assertSame('REPORT_NOT_FOUND', $exception->getMessage());
            }
        }
    }

    public function test_lifecycle_code_lists_preserve_manifest_order(): void
    {
        $candidateCodes = $this->candidateRegistry()->candidateCodes();
        $publishedCodes = $this->publishedRegistry()->publishedCodes();

        self::assertSame(['project_portfolio_health'], $candidateCodes);
        self::assertCount(27, $publishedCodes);
        self::assertSame('holding_performance', $publishedCodes[0]);
        self::assertSame('accepted_production_progress', $publishedCodes[6]);
        self::assertSame('customer_sla', $publishedCodes[26]);
    }

    public function test_published_registry_exposes_exact_manifest_sha256(): void
    {
        $manifest = $this->manifest();
        $hash = $this->publishedRegistry()->manifestSha256();

        self::assertInstanceOf(Sha256Hash::class, $hash);
        self::assertSame($manifest->bytesHash->value, $hash->value);
        self::assertSame(hash_file('sha256', $this->fixture('management.valid.yaml')), $hash->value);
    }

    public function test_definition_factory_hashes_only_canonical_raw_definition(): void
    {
        $row = $this->manifest()->definitions[1];
        $rowWithDifferentOrdinalContext = $row;
        $factory = $this->factory();

        $first = $factory->fromManifest($row);
        $factory->metadataFromManifest($rowWithDifferentOrdinalContext, 0);
        $second = $factory->fromManifest($rowWithDifferentOrdinalContext);

        self::assertSame($first->definitionHash->value, $second->definitionHash->value);
        self::assertSame(hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($row)), $first->definitionHash->value);
        self::assertSame('1.0.0', $first->contractVersion);
        self::assertSame('1.0.0', $first->formulaVersion);
        self::assertSame('operational', $first->snapshotClassification->value);
        self::assertSame('published', $first->publicationReadiness->value);
        self::assertTrue($first->supportsSubscriptions);
        self::assertSame('reports', $first->sourceModule);
        self::assertSame(ReportCoreAccessMode::REPORTING_WORKSPACE, $first->coreAccessMode);

        $sourceRow = $row;
        $sourceRow['source_module'] = 'act-reporting';
        $sourceRow['core_access_mode'] = 'source_module_report';
        $sourceRow['formats'] = ['xlsx'];
        $sourceRow['permissions'] = [
            'view' => ['act_reports.view'],
            'export' => ['act_reports.export.excel'],
            'sensitive' => [],
            'audit' => [],
        ];
        $source = $factory->fromManifest($sourceRow);
        self::assertSame('act-reporting', $source->sourceModule);
        self::assertSame(ReportCoreAccessMode::SOURCE_MODULE_REPORT, $source->coreAccessMode);
        self::assertNotSame($first->definitionHash->value, $source->definitionHash->value);
    }

    public function test_manifest_semantics_reject_reports_as_source_module(): void
    {
        $definitions = $this->manifest()->definitions;
        $definitions[0]['source_module'] = 'reports';
        $definitions[0]['core_access_mode'] = 'source_module_report';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_manifest_core_access_invalid');

        (new ReportManifestSemanticValidator)->assertManagement(['definitions' => $definitions]);
    }

    public function test_manifest_semantics_accept_owner_module_permissions(): void
    {
        $definitions = $this->manifest()->definitions;
        $definitions[0]['source_module'] = 'budgeting';
        $definitions[0]['core_access_mode'] = 'source_module_report';

        (new ReportManifestSemanticValidator)->assertManagement(['definitions' => $definitions]);

        self::assertSame('budgeting', $definitions[0]['source_module']);
    }

    public function test_metadata_preserves_explicit_contiguous_manifest_ordinal(): void
    {
        $published = $this->publishedRegistry();
        $metadata = new ManifestReportCatalogMetadataRegistry($this->manifest(), $this->factory(), $published);
        $ordinals = [];
        foreach ($published->publishedCodes() as $code) {
            $ordinals[] = $metadata->published($code)->manifestOrdinal;
        }

        self::assertSame(1, $metadata->published('holding_performance')->manifestOrdinal);
        self::assertSame(7, $metadata->published('accepted_production_progress')->manifestOrdinal);
        self::assertSame(27, $metadata->published('customer_sla')->manifestOrdinal);
        self::assertCount(27, array_unique($ordinals));
        self::assertSame(range(1, 27), $ordinals);
        self::assertSame('reports.catalog.holding_performance', $metadata->published('holding_performance')->titleKey);
    }

    public function test_metadata_lookup_is_fail_closed(): void
    {
        $published = $this->publishedRegistry();
        $metadata = new ManifestReportCatalogMetadataRegistry($this->manifest(), $this->factory(), $published);

        $this->expectException(ReportContractException::class);
        $this->expectExceptionMessage('REPORT_NOT_FOUND');

        $metadata->published('project_portfolio_health');
    }

    public function test_metadata_registry_rejects_manifest_hash_mismatch(): void
    {
        $manifest = $this->manifest();
        $differentManifest = new LoadedReportManifest(
            $manifest->catalog,
            $manifest->contractVersion,
            new Sha256Hash(str_repeat('f', 64)),
            $manifest->definitions,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_manifest_hash_mismatch');

        new ManifestReportCatalogMetadataRegistry($differentManifest, $this->factory(), $this->publishedRegistry());
    }

    public function test_scheduling_registry_uses_same_published_set_and_raw_capability(): void
    {
        $published = $this->publishedRegistry();
        $scheduling = new ManifestReportSchedulingCapabilityRegistry($this->manifest(), $this->factory(), $published);
        $capability = $scheduling->published('holding_performance');

        self::assertSame('holding_performance', $capability->code);
        self::assertTrue($capability->supportsSubscriptions);
        self::assertTrue($capability->reproducibleScheduledSnapshot);
        self::assertCount(27, $published->publishedCodes());
    }

    public function test_scheduling_registry_rejects_manifest_hash_mismatch(): void
    {
        $manifest = $this->manifest();
        $differentManifest = new LoadedReportManifest(
            $manifest->catalog,
            $manifest->contractVersion,
            new Sha256Hash(str_repeat('e', 64)),
            $manifest->definitions,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_manifest_hash_mismatch');

        new ManifestReportSchedulingCapabilityRegistry($differentManifest, $this->factory(), $this->publishedRegistry());
    }

    public function test_official_registry_exposes_only_m29_definition_and_hash(): void
    {
        $manifest = $this->loader()->loadOfficial(
            $this->fixture('official.valid.yaml'),
            $this->resource('official-document-catalog.v1.schema.json'),
        );
        $registry = new OfficialDocumentDefinitionRegistry($manifest);
        $definition = $registry->official('official_material_usage_m29');

        self::assertSame(['official_material_usage_m29'], $registry->codes());
        self::assertSame('official_material_usage_m29', $definition->code);
        self::assertSame('reports.official.official_material_usage_m29', $definition->titleKey);
        self::assertSame('blocked', $definition->publicationReadiness->value);
        self::assertSame('1.0.0', $definition->rendererVersion);
        self::assertSame('unassigned', $definition->legalRetentionPolicy);
        self::assertCount(7, $definition->sealRequires);
        self::assertSame($manifest->bytesHash->value, $registry->manifestSha256()->value);

        try {
            $registry->official('unknown_report');
            self::fail('Unknown official document must not be exposed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }
    }

    private function candidateRegistry(): YamlCandidateReportDefinitionRegistry
    {
        return new YamlCandidateReportDefinitionRegistry($this->manifest(), $this->factory());
    }

    private function publishedRegistry(): PublishedReportDefinitionRegistry
    {
        return new PublishedReportDefinitionRegistry($this->manifest(), $this->factory());
    }

    private function manifest(): LoadedReportManifest
    {
        return $this->loader()->loadManagement(
            $this->fixture('management.valid.yaml'),
            $this->resource('management-catalog.v1.schema.json'),
        );
    }

    private function loader(): YamlReportManifestLoader
    {
        return new YamlReportManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
            new ReportManifestSemanticValidator,
            new ReportPermissionCatalog,
        );
    }

    private function factory(): ReportDefinitionFactory
    {
        return new ReportDefinitionFactory;
    }

    private function fixture(string $file): string
    {
        return dirname(__DIR__, 3).'/Fixtures/Reporting/Manifest/'.$file;
    }

    private function resource(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }
}
