<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationState;
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
    public function it_marks_a_verified_recommendation_as_attempted_without_changing_the_cycle_or_local_estimates(): void
    {
        $coordinator = new ArbiterRemediationCoordinator;
        $draft = $this->draft();
        $recommended = $coordinator->recordShadowCycle(
            $draft,
            $this->targetedRebuildVerdict(),
            $this->inputHash(),
        );

        $attempted = $coordinator->markAttempted($recommended, $this->inputHash());

        self::assertSame($draft['local_estimates'], $attempted['local_estimates']);
        self::assertSame($recommended['arbiter_review']['cycle'], $attempted['arbiter_review']['cycle']);
        self::assertSame([
            'root_input_hash' => $this->inputHash(),
            'target_package_keys' => ['heating', 'ventilation'],
            'rebuild_attempted' => true,
            'phase' => 'attempted',
            'review_outcome' => null,
        ], $attempted['arbiter_review']['remediation']);
    }

    #[Test]
    public function it_routes_a_stale_root_cycle_to_human_review_without_remediation_or_local_estimate_changes(): void
    {
        $coordinator = new ArbiterRemediationCoordinator;
        $draft = $this->draft();
        $recommended = $coordinator->recordShadowCycle(
            $draft,
            $this->targetedRebuildVerdict(),
            $this->inputHash(),
        );
        $otherHash = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $reviewed = $coordinator->markAttempted($recommended, $otherHash);

        self::assertSame($draft['local_estimates'], $reviewed['local_estimates']);
        self::assertSame('human_review', $reviewed['arbiter_review']['outcome']);
        self::assertSame([], $reviewed['arbiter_review']['cycle']['target_package_keys']);
        self::assertArrayNotHasKey('remediation', $reviewed['arbiter_review']);
    }

    #[Test]
    public function it_rejects_a_second_attempt_when_a_valid_remediation_already_exists(): void
    {
        $coordinator = new ArbiterRemediationCoordinator;
        $draft = $this->draft();
        $attempted = $coordinator->markAttempted(
            $coordinator->recordShadowCycle($draft, $this->targetedRebuildVerdict(), $this->inputHash()),
            $this->inputHash(),
        );

        $reviewed = $coordinator->markAttempted($attempted, $this->inputHash());

        self::assertSame($draft['local_estimates'], $reviewed['local_estimates']);
        self::assertSame('human_review', $reviewed['arbiter_review']['outcome']);
        self::assertSame([], $reviewed['arbiter_review']['cycle']['target_package_keys']);
        self::assertArrayNotHasKey('remediation', $reviewed['arbiter_review']);
    }

    #[Test]
    public function it_resolves_an_attempted_remediation_as_passed_without_changing_the_root_or_targets(): void
    {
        $coordinator = new ArbiterRemediationCoordinator;
        $attempted = $coordinator->markAttempted(
            $coordinator->recordShadowCycle($this->draft(), $this->targetedRebuildVerdict(), $this->inputHash()),
            $this->inputHash(),
        );

        $resolved = $coordinator->resolveAfterRebuild($attempted, new ArbiterVerdict('passed', []));

        self::assertSame('passed', $resolved['arbiter_review']['outcome']);
        self::assertSame([
            'root_input_hash' => $this->inputHash(),
            'target_package_keys' => ['heating', 'ventilation'],
            'rebuild_attempted' => true,
            'phase' => 'reviewed',
            'review_outcome' => 'passed',
        ], $resolved['arbiter_review']['remediation']);
    }

    #[Test]
    public function it_routes_a_second_targeted_verdict_to_human_review_without_changing_local_estimates(): void
    {
        $coordinator = new ArbiterRemediationCoordinator;
        $draft = $this->draft();
        $attempted = $coordinator->markAttempted(
            $coordinator->recordShadowCycle($draft, $this->targetedRebuildVerdict(), $this->inputHash()),
            $this->inputHash(),
        );

        $resolved = $coordinator->resolveAfterRebuild($attempted, $this->targetedRebuildVerdict());

        self::assertSame($draft['local_estimates'], $resolved['local_estimates']);
        self::assertSame('human_review', $resolved['arbiter_review']['outcome']);
        self::assertSame('reviewed', $resolved['arbiter_review']['remediation']['phase']);
        self::assertSame('human_review', $resolved['arbiter_review']['remediation']['review_outcome']);
    }

    #[Test]
    #[DataProvider('remediationStatesWithoutRebuildAttempt')]
    public function it_rejects_remediation_states_without_an_attempted_rebuild(string $phase, ?string $reviewOutcome): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ArbiterRemediationState(
            $this->inputHash(),
            ['heating'],
            false,
            $phase,
            $reviewOutcome,
        );
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

    /** @return iterable<string, array{string, null|string}> */
    public static function remediationStatesWithoutRebuildAttempt(): iterable
    {
        yield 'attempted' => ['attempted', null];
        yield 'reviewed' => ['reviewed', 'passed'];
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
