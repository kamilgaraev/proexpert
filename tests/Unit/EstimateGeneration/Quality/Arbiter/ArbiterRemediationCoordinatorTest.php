<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArbiterRemediationCoordinatorTest extends TestCase
{
    #[Test]
    public function it_records_one_verified_shadow_recommendation_without_changing_local_estimates(): void
    {
        $draft = $this->draft();

        $reviewed = (new ArbiterRemediationCoordinator)->recordShadowCycle(
            $draft,
            $this->targetedRebuildVerdict(),
            $this->inputHash(),
        );

        self::assertSame($draft['local_estimates'], $reviewed['local_estimates']);
        self::assertSame('targeted_rebuild', $reviewed['arbiter_review']['outcome']);
        self::assertSame([
            'input_hash' => $this->inputHash(),
            'attempted' => false,
            'target_package_keys' => ['heating', 'ventilation'],
            'status' => 'shadow_recommendation',
            'terminal_outcome' => 'targeted_rebuild',
        ], $reviewed['arbiter_review']['cycle']);
    }

    #[Test]
    public function it_exhausts_an_identical_input_hash_without_changing_local_estimates(): void
    {
        $coordinator = new ArbiterRemediationCoordinator;
        $draft = $this->draft();
        $firstCycle = $coordinator->recordShadowCycle(
            $draft,
            $this->targetedRebuildVerdict(),
            $this->inputHash(),
        );

        $reviewed = $coordinator->recordShadowCycle(
            $firstCycle,
            $this->targetedRebuildVerdict(),
            $this->inputHash(),
        );

        self::assertSame($draft['local_estimates'], $reviewed['local_estimates']);
        self::assertSame('human_review', $reviewed['arbiter_review']['outcome']);
        self::assertSame([
            'input_hash' => $this->inputHash(),
            'attempted' => false,
            'target_package_keys' => [],
            'status' => 'cycle_exhausted',
            'terminal_outcome' => 'human_review',
        ], $reviewed['arbiter_review']['cycle']);
    }

    #[Test]
    #[DataProvider('invalidTargetedRebuildVerdicts')]
    public function it_requires_evidence_and_known_packages_without_changing_local_estimates(ArbiterVerdict $verdict): void
    {
        $draft = $this->draft();

        $reviewed = (new ArbiterRemediationCoordinator)->recordShadowCycle(
            $draft,
            $verdict,
            $this->inputHash(),
        );

        self::assertSame($draft['local_estimates'], $reviewed['local_estimates']);
        self::assertSame('human_review', $reviewed['arbiter_review']['outcome']);
        self::assertSame([
            'input_hash' => $this->inputHash(),
            'attempted' => false,
            'target_package_keys' => [],
            'status' => 'evidence_required',
            'terminal_outcome' => 'human_review',
        ], $reviewed['arbiter_review']['cycle']);
    }

    /** @return iterable<string, array{ArbiterVerdict}> */
    public static function invalidTargetedRebuildVerdicts(): iterable
    {
        yield 'missing evidence' => [new ArbiterVerdict('targeted_rebuild', [[
            'action' => 'rebuild',
            'package_keys' => ['heating'],
            'evidence_refs' => [],
        ]])];

        yield 'unknown package' => [new ArbiterVerdict('targeted_rebuild', [[
            'action' => 'rebuild',
            'package_keys' => ['unknown-package'],
            'evidence_refs' => ['evidence:1'],
        ]])];
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return [
            'local_estimates' => [
                [
                    'key' => 'ventilation',
                    'sections' => [[
                        'work_items' => [[
                            'name' => 'Ventilation unit',
                            'materials' => [['name' => 'Air duct', 'quantity' => 20]],
                        ]],
                    ]],
                ],
                [
                    'key' => 'heating',
                    'sections' => [[
                        'work_items' => [[
                            'name' => 'Heating boiler',
                            'materials' => [['name' => 'Boiler', 'quantity' => 1]],
                        ]],
                    ]],
                ],
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'outcome' => 'targeted_rebuild',
                'findings' => [],
            ],
        ];
    }

    private function targetedRebuildVerdict(): ArbiterVerdict
    {
        return new ArbiterVerdict('targeted_rebuild', [[
            'action' => 'rebuild',
            'package_keys' => ['ventilation', 'heating', 'ventilation'],
            'evidence_refs' => ['evidence:1'],
        ]]);
    }

    private function inputHash(): string
    {
        return 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }
}
