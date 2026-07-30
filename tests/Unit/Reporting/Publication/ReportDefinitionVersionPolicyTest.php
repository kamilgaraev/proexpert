<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Reporting\CatalogBindingTestFactory;

final class ReportDefinitionVersionPolicyTest extends TestCase
{
    #[DataProvider('schemaValidSemanticChangeProvider')]
    public function test_schema_valid_semantic_change_requires_matching_version_and_typed_evidence(
        string $dimension,
        callable $mutate,
    ): void {
        [$current, $candidate] = $this->loadedRows($mutate);

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed(
            $current,
            $candidate,
            $this->evidence($candidate),
        );

        self::assertTrue($diff->{$dimension});
    }

    public function test_filter_change_requires_both_source_and_contract_versions(): void
    {
        [$current, $candidate] = $this->loadedRows(static function (array &$row): void {
            $row['filters'] = [['id' => 'organization_id', 'weight' => 1.0]];
            $row['versions']['source_schema'] = '1.0.1';
            $row['versions']['contract'] = '1.0.1';
        });

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed(
            $current,
            $candidate,
            $this->evidence($candidate),
        );

        self::assertTrue($diff->sourceSchemaChanged);
        self::assertTrue($diff->contractChanged);
    }

    public function test_permission_and_readiness_only_change_preserves_semantic_versions(): void
    {
        [$current, $candidate] = $this->loadedRows(static function (array &$row): void {
            $row['permissions']['sensitive'] = ['budgeting.portfolio_dashboard.view'];
            $row['capabilities']['supports_subscriptions'] = true;
            $row['capabilities']['reproducible_scheduled_snapshot'] = true;
        });

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed(
            $current,
            $candidate,
            $this->evidence($candidate),
        );

        self::assertTrue($diff->permissionsChanged);
        self::assertTrue($diff->readinessChanged);
        self::assertFalse($diff->formulaChanged);
        self::assertFalse($diff->sourceSchemaChanged);
        self::assertFalse($diff->contractChanged);
        self::assertFalse($diff->rendererChanged);
    }

    public function test_formula_version_bump_with_stale_typed_evidence_is_rejected(): void
    {
        [$current, $candidate] = $this->loadedRows(static function (array &$row): void {
            $row['versions']['formula'] = '1.0.1';
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_evidence_mismatch');

        (new ReportDefinitionVersionPolicy)->assertAllowed(
            $current,
            $candidate,
            $this->evidence($current),
        );
    }

    public function test_renderer_change_without_greater_renderer_version_is_rejected(): void
    {
        [$current, $candidate] = $this->loadedRows(static function (array &$row): void {
            $row['category'] = 'portfolio_changed';
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_change_required');

        (new ReportDefinitionVersionPolicy)->assertAllowed(
            $current,
            $candidate,
            $this->evidence($candidate),
        );
    }

    public function test_contract_version_bump_without_contract_change_is_rejected(): void
    {
        [$current, $candidate] = $this->loadedRows(static function (array &$row): void {
            $row['versions']['contract'] = '1.0.1';
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_bump_without_change');

        (new ReportDefinitionVersionPolicy)->assertAllowed(
            $current,
            $candidate,
            $this->evidence($candidate),
        );
    }

    public static function schemaValidSemanticChangeProvider(): iterable
    {
        yield 'formula evidence' => [
            'formulaChanged',
            static function (array &$row): void {
                $row['versions']['formula'] = '1.0.1';
            },
        ];
        yield 'source schema evidence' => [
            'sourceSchemaChanged',
            static function (array &$row): void {
                $row['versions']['source_schema'] = '1.0.1';
            },
        ];
        yield 'API columns' => [
            'contractChanged',
            static function (array &$row): void {
                $row['columns'] = [['id' => 'project_name'], ['id' => 'status']];
                $row['versions']['contract'] = '1.0.1';
            },
        ];
        yield 'renderer metadata' => [
            'rendererChanged',
            static function (array &$row): void {
                $row['category'] = 'portfolio_changed';
                $row['versions']['renderer'] = '1.0.1';
            },
        ];
    }

    private function loadedRows(callable $mutate): array
    {
        $loader = $this->loader();
        $current = $loader->loadManagement($this->candidatePath(), $this->schemaPath());
        $document = Yaml::parseFile($this->candidatePath());
        $mutate($document['definitions'][0]);
        $bytes = Yaml::dump($document, 20, 2, Yaml::DUMP_OBJECT_AS_MAP);
        $candidate = $loader->loadManagement(
            'data://text/plain;base64,'.base64_encode($bytes),
            $this->schemaPath(),
        );

        return [$current->definitions[0], $candidate->definitions[0]];
    }

    private function evidence(array $row): ReportDefinitionConformanceEvidence
    {
        $definition = (new ReportDefinitionFactory)->fromManifest($row);
        $binding = CatalogBindingTestFactory::binding($definition);

        return CatalogBindingTestFactory::evidence(
            $definition,
            $binding,
            new Sha256Hash(str_repeat('f', 64)),
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

    private function candidatePath(): string
    {
        return dirname(__DIR__, 4).'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml';
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json';
    }
}
