<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Application\Catalog\WaveOneCandidateBindingSet;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateManifest;
use App\BusinessModules\Core\Reporting\Domain\Enums\WaveOneCandidateBindingStatus;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlWaveOneCandidateManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\ReportSchemaValidationException;
use InvalidArgumentException;
use LogicException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WaveOneCandidateBindingSetTest extends TestCase
{
    public function test_preserves_manifest_order_and_excludes_blocked_candidates_from_implemented_bindings(): void
    {
        $manifest = $this->manifest();
        $set = new WaveOneCandidateBindingSet($manifest, $this->bindings($manifest));

        self::assertSame(
            [
                'project_portfolio_health',
                'portfolio_liquidity',
                'baseline_schedule_variance',
                'project_margin',
                'budget_plan_fact',
                'wip_completion_forecast',
                'contract_settlement_exposure',
                'management_pnl',
                'workforce_capacity',
                'attendance_execution',
                'project_labor_cost',
                'payroll_readiness',
            ],
            array_map(static fn (WaveOneCandidateBinding $binding): string => $binding->code, $set->ordered()),
        );
        self::assertCount(4, $set->implemented());
        self::assertSame('blocked_by_source_contract', $set->ordered()[6]->status->value);
        self::assertNull($set->ordered()[6]->provider);
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
            'blocked with provider' => [static function (array $bindings): array {
                $bindings[2] = new WaveOneCandidateBinding(
                    $bindings[2]->code,
                    WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_CONTRACT,
                    self::provider(),
                );

                return $bindings;
            }],
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
                $candidate->sourceStatus === 'implemented'
                    ? WaveOneCandidateBindingStatus::IMPLEMENTED
                    : WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_CONTRACT,
                $candidate->sourceStatus === 'implemented' ? self::provider() : null,
            ),
            $manifest->ordered(),
        );
    }

    private static function provider(): ReportDataProvider
    {
        return new class implements ReportDataProvider
        {
            public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
            {
                throw new LogicException('not_called');
            }

            public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
            {
                throw new LogicException('not_called');
            }
        };
    }

    private function resource(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }
}
