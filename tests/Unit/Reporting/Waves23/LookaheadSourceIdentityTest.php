<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadEligibilityInput;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessFormula;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LookaheadSourceIdentityTest extends TestCase
{
    public function test_every_formula_projection_and_historical_state_dimension_changes_source_identity(): void
    {
        $variants = [
            $this->input(),
            $this->input(taskId: 102),
            $this->input(container: true),
            $this->input(taskType: 'container'),
            $this->input(status: 'in_progress'),
            $this->input(plannedStart: '2026-08-06T00:00:00+00:00'),
            $this->input(asOf: '2026-07-30T00:00:00+00:00'),
            $this->input(projectId: 202),
            $this->input(scheduleId: 302),
            $this->input(wbsCode: '1.2'),
            $this->input(ownerId: 402),
            $this->input(contractorId: 502),
            $this->input(zoneId: 602),
            $this->input(taskStateVersion: 8),
            $this->input(taskStateSourceHash: str_repeat('b', 64)),
            $this->input(taskStateEffectiveAt: '2026-07-29T11:00:00+00:00'),
            $this->input(constraintStatus: 'resolved'),
        ];

        $hashes = array_map(
            static fn (LookaheadEligibilityInput $input): string => hash(
                'sha256',
                CanonicalJson::encode($input->canonicalIdentity()),
            ),
            $variants,
        );

        self::assertCount(count($variants), array_unique($hashes, SORT_STRING));
    }

    public function test_container_state_changes_eligibility(): void
    {
        $formula = new LookaheadReadinessFormula;
        $policy = new LookaheadReadinessPolicyVersion(
            1,
            10,
            30,
            ['planned'],
            ['permit'],
            ['high'],
            true,
            new DateTimeImmutable('2026-07-01T00:00:00+00:00'),
            null,
            'UTC',
            str_repeat('c', 64),
        );

        self::assertTrue($formula->evaluate($this->input(), $policy)->eligible);
        self::assertFalse($formula->evaluate($this->input(container: true, taskType: 'container'), $policy)->eligible);
    }

    private function input(
        int $taskId = 101,
        bool $container = false,
        string $taskType = 'task',
        string $status = 'planned',
        string $plannedStart = '2026-08-05T00:00:00+00:00',
        string $asOf = '2026-07-29T00:00:00+00:00',
        int $projectId = 201,
        int $scheduleId = 301,
        ?string $wbsCode = '1.1',
        ?int $ownerId = 401,
        ?int $contractorId = 501,
        ?int $zoneId = 601,
        int $taskStateVersion = 7,
        string $taskStateSourceHash = '',
        string $taskStateEffectiveAt = '2026-07-29T10:00:00+00:00',
        string $constraintStatus = 'open',
    ): LookaheadEligibilityInput {
        return new LookaheadEligibilityInput(
            taskId: $taskId,
            container: $container,
            status: $status,
            plannedStart: new DateTimeImmutable($plannedStart),
            asOf: new DateTimeImmutable($asOf),
            constraints: [
                new LookaheadConstraintState(
                    701,
                    'permit',
                    'high',
                    $constraintStatus,
                    null,
                    null,
                    new DateTimeImmutable('2026-07-20T00:00:00+00:00'),
                ),
            ],
            projectId: $projectId,
            scheduleId: $scheduleId,
            wbsCode: $wbsCode,
            ownerId: $ownerId,
            contractorId: $contractorId,
            zoneId: $zoneId,
            taskType: $taskType,
            taskStateVersion: $taskStateVersion,
            taskStateSourceHash: $taskStateSourceHash === '' ? str_repeat('a', 64) : $taskStateSourceHash,
            taskStateEffectiveAt: new DateTimeImmutable($taskStateEffectiveAt),
        );
    }
}
