<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportDefinitionVersionPolicyTest extends TestCase
{
    #[DataProvider('semanticChangeProvider')]
    public function test_semantic_change_requires_only_its_matching_version_bump(
        string $field,
        mixed $value,
        string $version,
    ): void {
        $current = $this->definition();
        $candidate = $current;
        $candidate[$field] = $value;
        $candidate['versions'][$version] = '1.0.1';

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate);

        self::assertTrue(match ($version) {
            'formula' => $diff->formulaChanged,
            'source_schema' => $diff->sourceSchemaChanged,
            'contract' => $diff->contractChanged,
            'renderer' => $diff->rendererChanged,
        });
    }

    public function test_permission_and_readiness_only_change_preserves_semantic_versions(): void
    {
        $current = $this->definition();
        $candidate = $current;
        $candidate['permissions']['view'] = ['reports.changed.view'];
        $candidate['readiness']['publication'] = 'candidate';

        $diff = (new ReportDefinitionVersionPolicy)->assertAllowed($current, $candidate);

        self::assertTrue($diff->permissionsChanged);
        self::assertTrue($diff->readinessChanged);
        self::assertFalse($diff->formulaChanged);
        self::assertFalse($diff->sourceSchemaChanged);
        self::assertFalse($diff->contractChanged);
        self::assertFalse($diff->rendererChanged);
    }

    public function test_version_bump_without_matching_semantic_change_is_rejected(): void
    {
        $candidate = $this->definition();
        $candidate['versions']['formula'] = '1.0.1';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_bump_without_change');

        (new ReportDefinitionVersionPolicy)->assertAllowed($this->definition(), $candidate);
    }

    public function test_semantic_change_without_greater_matching_version_is_rejected(): void
    {
        $candidate = $this->definition();
        $candidate['filters'] = [['id' => 'organization_id']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_definition_version_change_required');

        (new ReportDefinitionVersionPolicy)->assertAllowed($this->definition(), $candidate);
    }

    public static function semanticChangeProvider(): iterable
    {
        yield 'formula' => ['formula', ['expression' => 'sum(amount)'], 'formula'];
        yield 'source schema' => ['source', ['table' => 'report_rows_v2'], 'source_schema'];
        yield 'API contract' => ['filters', [['id' => 'organization_id']], 'contract'];
        yield 'renderer' => ['title_key', 'reports.catalog.changed', 'renderer'];
    }

    private function definition(): array
    {
        return [
            'code' => 'quality_report',
            'title_key' => 'reports.catalog.quality_report',
            'catalog_group' => 'quality_safety',
            'category' => 'quality',
            'grain' => 'project',
            'wave' => 1,
            'filters' => [['id' => 'project_id']],
            'columns' => [['id' => 'name']],
            'sorts' => [['id' => 'name']],
            'formats' => ['xlsx'],
            'formula' => ['expression' => 'sum(total)'],
            'source' => ['table' => 'report_rows_v1'],
            'versions' => [
                'contract' => '1.0.0',
                'formula' => '1.0.0',
                'source_schema' => '1.0.0',
                'renderer' => '1.0.0',
            ],
            'permissions' => [
                'view' => ['reports.view'],
                'export' => ['reports.export'],
                'sensitive' => [],
                'audit' => [],
            ],
            'readiness' => [
                'source' => 'ready',
                'formula' => 'ready',
                'delivery' => 'verified',
                'publication' => 'draft',
            ],
            'capabilities' => [
                'supports_subscriptions' => false,
                'reproducible_scheduled_snapshot' => false,
            ],
        ];
    }
}
