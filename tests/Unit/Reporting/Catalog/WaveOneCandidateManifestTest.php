<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidate;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlWaveOneCandidateManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\ReportSchemaValidationException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WaveOneCandidateManifestTest extends TestCase
{
    public function test_production_manifest_loads_the_closed_candidate_identity_set_in_literal_order(): void
    {
        $manifest = $this->loader()->load(
            $this->resource('candidates/wave-1-candidates.v1.yaml'),
            $this->resource('candidates/wave-1-candidates.v1.schema.json'),
        );

        self::assertSame(
            [
                [1, 'G01', 'project_portfolio_health', 'wave1.project_portfolio_health', 'implemented', 'candidate'],
                [2, 'G04', 'portfolio_liquidity', 'wave1.portfolio_liquidity', 'implemented', 'candidate'],
                [3, 'G06', 'baseline_schedule_variance', 'wave1.baseline_schedule_variance', 'source contract required', 'candidate'],
                [4, 'G09', 'project_margin', 'wave1.project_margin', 'implemented', 'candidate'],
                [5, 'G10', 'budget_plan_fact', 'wave1.budget_plan_fact', 'implemented', 'candidate'],
                [6, 'G11', 'wip_completion_forecast', 'wave1.wip_completion_forecast', 'source contract required', 'candidate'],
                [7, 'G12', 'contract_settlement_exposure', 'wave1.contract_settlement_exposure', 'source/formula contract required', 'candidate'],
                [8, 'G13', 'management_pnl', 'wave1.management_pnl', 'source/formula contract required', 'candidate'],
                [9, 'G21', 'workforce_capacity', 'wave1.workforce_capacity', 'source contract required', 'candidate'],
                [10, 'G22', 'attendance_execution', 'wave1.attendance_execution', 'source contract required', 'candidate'],
                [11, 'G23', 'project_labor_cost', 'wave1.project_labor_cost', 'source contract required', 'candidate'],
                [12, 'G24', 'payroll_readiness', 'wave1.payroll_readiness', 'source contract required', 'candidate'],
            ],
            array_map(
                static fn (WaveOneCandidate $item): array => [
                    $item->ordinal,
                    $item->groupId,
                    $item->code,
                    $item->family,
                    $item->sourceStatus,
                    $item->publication,
                ],
                $manifest->ordered(),
            ),
        );
    }

    #[DataProvider('invalidManifestMutations')]
    public function test_schema_rejects_forbidden_candidate_manifest_fields_and_publications(callable $mutation): void
    {
        $path = $this->temporary($mutation($this->manifestBytes()));

        try {
            $this->expectException(ReportSchemaValidationException::class);
            $this->loader()->load($path, $this->resource('candidates/wave-1-candidates.v1.schema.json'));
        } finally {
            unlink($path);
        }
    }

    public static function invalidManifestMutations(): array
    {
        return [
            'unknown field' => [static fn (string $bytes): string => str_replace("contract_version: 1.0.0\n", "contract_version: 1.0.0\nunknown_field: true\n", $bytes)],
            'active field' => [static fn (string $bytes): string => preg_replace('/publication: candidate}/', 'publication: candidate, active: true}', $bytes, 1) ?? throw new RuntimeException('wave_one_candidate_manifest_fixture_mutation_failed')],
            'published field' => [static fn (string $bytes): string => preg_replace('/publication: candidate}/', 'publication: published}', $bytes, 1) ?? throw new RuntimeException('wave_one_candidate_manifest_fixture_mutation_failed')],
            'readiness field' => [static fn (string $bytes): string => preg_replace('/publication: candidate}/', 'publication: candidate, readiness: ready}', $bytes, 1) ?? throw new RuntimeException('wave_one_candidate_manifest_fixture_mutation_failed')],
            'provider class field' => [static fn (string $bytes): string => preg_replace('/publication: candidate}/', 'publication: candidate, provider_class: Example\\Provider}', $bytes, 1) ?? throw new RuntimeException('wave_one_candidate_manifest_fixture_mutation_failed')],
            'provider classes field' => [static fn (string $bytes): string => preg_replace('/publication: candidate}/', 'publication: candidate, provider_classes: [Example\\Provider]}', $bytes, 1) ?? throw new RuntimeException('wave_one_candidate_manifest_fixture_mutation_failed')],
            'invalid publication' => [static fn (string $bytes): string => preg_replace('/publication: candidate}/', 'publication: draft}', $bytes, 1) ?? throw new RuntimeException('wave_one_candidate_manifest_fixture_mutation_failed')],
        ];
    }

    private function loader(): YamlWaveOneCandidateManifestLoader
    {
        return new YamlWaveOneCandidateManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
        );
    }

    private function resource(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }

    private function manifestBytes(): string
    {
        $bytes = file_get_contents($this->resource('candidates/wave-1-candidates.v1.yaml'));
        if ($bytes === false) {
            throw new RuntimeException('wave_one_candidate_manifest_fixture_unreadable');
        }

        return $bytes;
    }

    private function temporary(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-one-candidates-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('wave_one_candidate_manifest_fixture_write_failed');
        }

        return $path;
    }
}
