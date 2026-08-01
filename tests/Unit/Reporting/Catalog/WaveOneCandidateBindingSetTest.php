<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\WaveOneCandidateBindingSet;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateManifest;
use App\BusinessModules\Core\Reporting\Domain\Enums\WaveOneCandidateBindingStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlWaveOneCandidateManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\ReportSchemaValidationException;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaveOneCandidateBindingSetTest extends TestCase
{
    public function test_preserves_literal_manifest_order_with_only_blocked_candidates_and_null_providers(): void
    {
        $manifest = $this->manifest();
        $set = new WaveOneCandidateBindingSet($manifest, $this->bindings($manifest));

        self::assertSame([
            ['project_portfolio_health', 'blocked_by_source_readiness', true],
            ['portfolio_liquidity', 'blocked_by_source_readiness', true],
            ['baseline_schedule_variance', 'blocked_by_source_contract', true],
            ['project_margin', 'blocked_by_source_readiness', true],
            ['budget_plan_fact', 'blocked_by_source_readiness', true],
            ['wip_completion_forecast', 'blocked_by_source_contract', true],
            ['contract_settlement_exposure', 'blocked_by_source_contract', true],
            ['management_pnl', 'blocked_by_source_contract', true],
            ['workforce_capacity', 'blocked_by_source_contract', true],
            ['attendance_execution', 'blocked_by_source_contract', true],
            ['project_labor_cost', 'blocked_by_source_contract', true],
            ['payroll_readiness', 'blocked_by_source_contract', true],
        ], array_map(
            static fn (WaveOneCandidateBinding $binding): array => [
                $binding->code,
                $binding->status->value,
                $binding->provider === null,
            ],
            $set->ordered(),
        ));
        self::assertSame([], $set->implemented());
    }

    #[DataProvider('invalidBindingMutations')]
    public function test_rejects_binding_sets_that_do_not_exactly_match_manifest_or_source_contract(callable $mutation): void
    {
        $manifest = $this->manifest();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wave_one_candidate_binding_set_invalid');

        new WaveOneCandidateBindingSet($manifest, $mutation($this->bindings($manifest)));
    }

    public static function invalidBindingMutations(): array
    {
        return [
            'duplicate code' => [static function (array $bindings): array {
                $bindings[1] = $bindings[0];

                return $bindings;
            }],
            'missing code' => [static fn (array $bindings): array => array_slice($bindings, 1)],
            'reordered code' => [static function (array $bindings): array {
                [$bindings[0], $bindings[1]] = [$bindings[1], $bindings[0]];

                return $bindings;
            }],
            'extra code' => [static function (array $bindings): array {
                $bindings[] = new WaveOneCandidateBinding(
                    'extra_candidate',
                    WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_CONTRACT,
                    null,
                );

                return $bindings;
            }],
            'implemented without provider' => [static function (array $bindings): array {
                $bindings[0] = new WaveOneCandidateBinding(
                    $bindings[0]->code,
                    WaveOneCandidateBindingStatus::IMPLEMENTED,
                    null,
                );

                return $bindings;
            }],
        ];
    }

    #[DataProvider('candidateCodes')]
    public function test_rejects_promotion_of_every_candidate_to_implemented_with_a_provider(string $code): void
    {
        $manifest = $this->manifest();
        $bindings = $this->bindings($manifest);
        $index = array_search($code, array_column($bindings, 'code'), true);

        self::assertIsInt($index);
        $bindings[$index] = new WaveOneCandidateBinding(
            $code,
            WaveOneCandidateBindingStatus::IMPLEMENTED,
            $this->createMock(ReportDataProvider::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wave_one_candidate_binding_set_invalid');

        new WaveOneCandidateBindingSet($manifest, $bindings);
    }

    public function test_rejects_a_provider_for_a_candidate_blocked_by_source_readiness(): void
    {
        $manifest = $this->manifest();
        $bindings = $this->bindings($manifest);
        $bindings[0] = new WaveOneCandidateBinding(
            $bindings[0]->code,
            WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_READINESS,
            $this->createMock(ReportDataProvider::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('wave_one_candidate_binding_set_invalid');

        new WaveOneCandidateBindingSet($manifest, $bindings);
    }

    public static function candidateCodes(): array
    {
        return [
            'G01' => ['project_portfolio_health'],
            'G04' => ['portfolio_liquidity'],
            'G06' => ['baseline_schedule_variance'],
            'G09' => ['project_margin'],
            'G10' => ['budget_plan_fact'],
            'G11' => ['wip_completion_forecast'],
            'G12' => ['contract_settlement_exposure'],
            'G13' => ['management_pnl'],
            'G21' => ['workforce_capacity'],
            'G22' => ['attendance_execution'],
            'G23' => ['project_labor_cost'],
            'G24' => ['payroll_readiness'],
        ];
    }

    private function manifest(): WaveOneCandidateManifest
    {
        return (new YamlWaveOneCandidateManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
        ))->load(
            $this->resource('candidates/wave-1-candidates.v1.yaml'),
            $this->resource('candidates/wave-1-candidates.v1.schema.json'),
        );
    }

    private function bindings(WaveOneCandidateManifest $manifest): array
    {
        return array_map(
            static fn ($candidate): WaveOneCandidateBinding => new WaveOneCandidateBinding(
                $candidate->code,
                match ($candidate->sourceStatus) {
                    'source readiness required' => WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_READINESS,
                    'source contract required', 'source/formula contract required' => WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_CONTRACT,
                },
                null,
            ),
            $manifest->ordered(),
        );
    }

    private function resource(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }
}
