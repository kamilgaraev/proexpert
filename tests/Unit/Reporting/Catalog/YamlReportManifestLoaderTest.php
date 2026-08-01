<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\ReportSchemaValidationException;
use InvalidArgumentException;
use LogicException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class YamlReportManifestLoaderTest extends TestCase
{
    public function test_management_manifest_is_loaded_from_exact_utf8_bytes_without_reordering(): void
    {
        $path = $this->fixture('management.valid.yaml');
        $manifest = $this->loader()->loadManagement($path, $this->schema('management-catalog.v1.schema.json'));

        self::assertSame('management-catalog.v1', $manifest->catalog);
        self::assertSame('1.0.0', $manifest->contractVersion);
        self::assertInstanceOf(Sha256Hash::class, $manifest->bytesHash);
        self::assertSame(hash_file('sha256', $path), $manifest->bytesHash->value);
        self::assertCount(28, $manifest->definitions);
        self::assertTrue(array_is_list($manifest->definitions));
        self::assertSame('project_portfolio_health', $manifest->definitions[0]['code']);
        self::assertSame('portfolio', $manifest->definitions[0]['catalog_group']);
        self::assertSame('candidate', $manifest->definitions[0]['readiness']['publication']);
        self::assertIsFloat($manifest->definitions[0]['filters'][0]['weight']);
        self::assertSame(1.0, $manifest->definitions[0]['filters'][0]['weight']);
        self::assertSame('holding_performance', $manifest->definitions[1]['code']);
        self::assertSame('published', $manifest->definitions[1]['readiness']['publication']);
        self::assertSame('accepted_production_progress', $manifest->definitions[7]['code']);
        self::assertSame(3, $manifest->definitions[7]['wave']);
        self::assertSame('customer_sla', $manifest->definitions[27]['code']);
        self::assertSame('partners_customers', $manifest->definitions[27]['catalog_group']);
    }

    public function test_official_manifest_loads_only_m29(): void
    {
        $path = $this->fixture('official.valid.yaml');
        $manifest = $this->loader()->loadOfficial($path, $this->schema('official-document-catalog.v1.schema.json'));

        self::assertSame('official-document-catalog.v1', $manifest->catalog);
        self::assertSame('1.0.0', $manifest->contractVersion);
        self::assertInstanceOf(Sha256Hash::class, $manifest->bytesHash);
        self::assertSame(hash_file('sha256', $path), $manifest->bytesHash->value);
        self::assertCount(1, $manifest->definitions);
        self::assertSame('official_material_usage_m29', $manifest->definitions[0]['code']);
        self::assertSame('reports.official.official_material_usage_m29', $manifest->definitions[0]['title_key']);
        self::assertSame('blocked', $manifest->definitions[0]['publication_readiness']);
        self::assertCount(7, $manifest->definitions[0]['seal_requires']);
    }

    public function test_invalid_utf8_fails_before_yaml_parsing(): void
    {
        $path = $this->temporary("\xC3\x28");

        try {
            $this->loader()->loadManagement($path, $this->schema('management-catalog.v1.schema.json'));
            self::fail('Invalid UTF-8 must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertSame('report_manifest_utf8_invalid', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_unknown_schema_field_fails_closed(): void
    {
        $bytes = (string) file_get_contents($this->fixture('management.valid.yaml'));
        $path = $this->temporary(str_replace(
            "contract_version: 1.0.0\n",
            "contract_version: 1.0.0\nunknown_field: true\n",
            $bytes,
        ));

        try {
            $this->loader()->loadManagement($path, $this->schema('management-catalog.v1.schema.json'));
            self::fail('Unknown schema fields must be rejected.');
        } catch (ReportSchemaValidationException $exception) {
            self::assertSame('most.management-catalog.v1', $exception->schemaId);
            self::assertSame('report_schema_invalid', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_invalid_catalog_group_fails_closed(): void
    {
        $this->expectException(ReportSchemaValidationException::class);

        $this->loader()->loadManagement(
            $this->fixture('management.invalid-group.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
    }

    public function test_duplicate_management_code_fails_semantic_validation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_manifest_code_duplicate');

        $this->loader()->loadManagement(
            $this->fixture('management.duplicate-code.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
    }

    public function test_unknown_or_untranslated_permission_fails_closed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_permission_unknown_or_untranslated');

        $this->loader()->loadManagement(
            $this->fixture('management.unknown-permission.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
    }

    public function test_published_definition_with_partial_source_fails_schema(): void
    {
        $this->expectException(ReportSchemaValidationException::class);

        $this->loader()->loadManagement(
            $this->fixture('management.invalid-readiness.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
    }

    public function test_candidate_with_empty_contract_capability_fails_schema(): void
    {
        $this->expectException(ReportSchemaValidationException::class);

        $this->loader()->loadManagement(
            $this->fixture('management.candidate-empty-capability.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
    }

    public function test_management_catalog_rejects_m29(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_manifest_m29_catalog_invalid');

        $this->loader()->loadManagement(
            $this->fixture('management.contains-m29.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
    }

    public function test_production_management_manifest_satisfies_permission_and_identity_semantics(): void
    {
        $manifest = $this->loader()->loadManagement(
            $this->resource('management-catalog.v1.yaml'),
            $this->schema('management-catalog.v1.schema.json'),
        );
        $waves = array_count_values(array_column($manifest->definitions, 'wave'));
        ksort($waves);

        self::assertCount(28, $manifest->definitions);
        self::assertSame([1 => 12, 2 => 10, 3 => 6], $waves);
        self::assertSame('management-catalog.v1', $manifest->catalog);
        self::assertSame('1.0.0', $manifest->contractVersion);
        self::assertSame('project_portfolio_health', $manifest->definitions[0]['code']);
        self::assertSame('holding_performance', $manifest->definitions[1]['code']);
        self::assertSame('intercompany_contract_flows', $manifest->definitions[2]['code']);
        self::assertSame(
            28,
            count(array_unique(array_column($manifest->definitions, 'code'))),
        );
        self::assertCount(7, array_unique(array_column($manifest->definitions, 'catalog_group')));
        self::assertSame(64, strlen($manifest->bytesHash->value));
        self::assertNotContains('official_material_usage_m29', array_column($manifest->definitions, 'code'));
    }

    public function test_loaded_manifest_rejects_wrong_catalog_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_manifest_definition_count_invalid');

        new LoadedReportManifest(
            'management-catalog.v1',
            '1.0.0',
            new Sha256Hash(str_repeat('a', 64)),
            [['code' => 'one_report']],
        );
    }

    public function test_loaded_manifest_rejects_duplicate_codes_independently_of_loader(): void
    {
        $definitions = array_fill(0, 28, ['code' => 'same_report']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_manifest_code_duplicate');

        new LoadedReportManifest(
            'management-catalog.v1',
            '1.0.0',
            new Sha256Hash(str_repeat('a', 64)),
            $definitions,
        );
    }

    public function test_permission_catalog_rejects_same_namespace_unknown_permission(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('report_permission_unknown_or_untranslated');

        (new ReportPermissionCatalog)->assertKnownAndTranslated(['budgeting.nonexistent.view']);
    }

    public function test_permission_catalog_rejects_known_permission_without_exact_translation(): void
    {
        [$root, $roles, $modules, $translations] = $this->permissionSources(
            ['finance.report.view'],
            [],
        );

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('report_permission_unknown_or_untranslated');
            (new ReportPermissionCatalog($roles, $modules, $translations))
                ->assertKnownAndTranslated(['finance.report.view']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_permission_catalog_accepts_explicit_wildcard_with_exact_translation(): void
    {
        [$root, $roles, $modules, $translations] = $this->permissionSources(
            ['reports.*'],
            ['reports.future.view' => 'Просмотр будущего отчёта'],
        );

        try {
            self::expectNotToPerformAssertions();
            (new ReportPermissionCatalog($roles, $modules, $translations))
                ->assertKnownAndTranslated(['reports.future.view']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_permission_catalog_ignores_permission_like_strings_outside_permission_collections(): void
    {
        [$root, $roles, $modules, $translations] = $this->permissionSources(
            [],
            ['finance.report.view' => 'Просмотр финансового отчёта'],
        );
        file_put_contents(
            $modules.'/module.json',
            json_encode(['description' => 'finance.report.view', 'permissions' => []], JSON_THROW_ON_ERROR),
        );

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('report_permission_unknown_or_untranslated');
            (new ReportPermissionCatalog($roles, $modules, $translations))
                ->assertKnownAndTranslated(['finance.report.view']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_loader_object_conversion_preserves_nested_float_and_list_map_semantics(): void
    {
        $method = new \ReflectionMethod(YamlReportManifestLoader::class, 'toObjectGraph');
        $converted = $method->invoke($this->loader(), [
            'nested' => ['ratio' => 1.0],
            'list' => [['value' => 1.0]],
        ]);

        self::assertIsObject($converted);
        self::assertIsObject($converted->nested);
        self::assertIsFloat($converted->nested->ratio);
        self::assertSame(1.0, $converted->nested->ratio);
        self::assertIsArray($converted->list);
        self::assertIsObject($converted->list[0]);
        self::assertIsFloat($converted->list[0]->value);
    }

    private function loader(): YamlReportManifestLoader
    {
        return new YamlReportManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
            new ReportManifestSemanticValidator,
            new ReportPermissionCatalog,
        );
    }

    private function fixture(string $file): string
    {
        return dirname(__DIR__, 3).'/Fixtures/Reporting/Manifest/'.$file;
    }

    private function schema(string $file): string
    {
        return $this->resource($file);
    }

    private function resource(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }

    private function temporary(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-manifest-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('report_test_fixture_write_failed');
        }

        return $path;
    }

    private function permissionSources(array $permissions, array $values): array
    {
        $root = sys_get_temp_dir().'/report-permissions-'.bin2hex(random_bytes(8));
        $roles = $root.'/roles';
        $modules = $root.'/modules';
        $translations = $root.'/permissions.php';
        if (! mkdir($roles, 0777, true) || ! mkdir($modules, 0777, true)) {
            throw new RuntimeException('report_test_fixture_write_failed');
        }

        $role = json_encode(['system_permissions' => $permissions], JSON_THROW_ON_ERROR);
        $translationSource = "<?php\nreturn ".var_export([
            'values' => $values,
            'subjects' => [],
            'actions' => [],
        ], true).";\n";
        if (file_put_contents($roles.'/role.json', $role) === false
            || file_put_contents($translations, $translationSource) === false) {
            throw new RuntimeException('report_test_fixture_write_failed');
        }

        return [$root, $roles, $modules, $translations];
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
