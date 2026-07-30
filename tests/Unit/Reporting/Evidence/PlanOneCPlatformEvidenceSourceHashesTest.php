<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneCPlatformEvidenceBuilder;
use PHPUnit\Framework\TestCase;

final class PlanOneCPlatformEvidenceSourceHashesTest extends TestCase
{
    public function test_requires_complete_task_twelve_source_provenance_hashes(): void
    {
        $root = dirname(__DIR__, 4);
        $builder = new PlanOneCPlatformEvidenceBuilder($root);
        $method = new \ReflectionMethod($builder, 'sourceHashes');
        $hashes = $method->invoke($builder);
        $expectedPaths = [
            'manifest' => 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.yaml',
            'official_manifest' => 'app/BusinessModules/Core/Reporting/resources/official-document-catalog.v1.yaml',
            'generated_catalog' => 'docs/reports/generated/reporting-catalog.v1.json',
            'resource' => 'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
            'permission' => 'docs/reports/generated/report-permissions.v1.json',
            'translation' => 'lang/ru/reports.php',
            'route' => 'app/BusinessModules/Core/Reporting/routes.php',
            'schema' => 'app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json',
            'candidate_validation' => 'docs/reports/contracts/report-candidate-validation.schema.json',
            'conformance_framework' => 'docs/reports/contracts/report-conformance-evidence.schema.json',
            'publication_framework' => 'docs/reports/contracts/report-publication-ledger.schema.json',
            'platform_quality_ledger' => 'docs/reports/contracts/report-quality-evidence.schema.json',
        ];

        self::assertSame(array_keys($expectedPaths), array_keys($hashes));
        foreach ($expectedPaths as $key => $path) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashes[$key]);
            self::assertSame(hash_file('sha256', $root.'/'.$path), $hashes[$key]);
        }
    }
}
