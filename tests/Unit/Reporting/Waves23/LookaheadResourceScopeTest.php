<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\DTO\LookaheadConstraintState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadResourceScope;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LookaheadResourceScopeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4).'/app/Support/Reporting/ReportScopedResourceFilter.php';
        require_once dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/DTO/'
            .'LookaheadConstraintState.php';
        require_once dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/ScheduleManagement/Reporting/Lookahead/Services/'
            .'LookaheadResourceScope.php';
    }

    #[Test]
    public function constraint_scope_removes_unrelated_constraints_and_tasks_without_an_allowed_constraint(): void
    {
        $scope = $this->scope([
            new ReportScopedResource('constraint', 51, 7),
        ]);
        $selector = new LookaheadResourceScope;

        self::assertSame([51], array_map(
            static fn (LookaheadConstraintState $constraint): int => $constraint->constraintId,
            $selector->filterConstraints(
                $scope,
                7,
                11,
                41,
                [$this->constraint(51), $this->constraint(52)],
            ) ?? [],
        ));
        self::assertNull($selector->filterConstraints(
            $scope,
            7,
            11,
            42,
            [$this->constraint(52)],
        ));
        self::assertNull($selector->filterConstraints($scope, 7, 11, 43, []));
    }

    #[Test]
    public function linked_resource_scope_is_applied_before_rows_and_totals_are_built(): void
    {
        $scope = $this->scope([
            new ReportScopedResource('purchase_request', 61, 7),
        ]);
        $selector = new LookaheadResourceScope;

        self::assertSame([51], array_map(
            static fn (LookaheadConstraintState $constraint): int => $constraint->constraintId,
            $selector->filterConstraints(
                $scope,
                7,
                11,
                41,
                [
                    $this->constraint(51, 'purchase_request', 61),
                    $this->constraint(52, 'purchase_request', 62),
                    $this->constraint(53),
                ],
            ) ?? [],
        ));
        self::assertNull($selector->filterConstraints(
            $scope,
            7,
            11,
            42,
            [$this->constraint(52, 'purchase_request', 62)],
        ));
    }

    #[Test]
    public function task_kind_present_only_in_another_project_produces_an_empty_candidate_universe(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [7, 8],
            [new ReportScopedResource('task', 41, 7)],
            new DateTimeZone('Europe/Moscow'),
        );

        self::assertNull((new LookaheadResourceScope)->filterConstraints(
            $scope,
            8,
            21,
            42,
            [],
        ));
    }

    private function scope(array $resources): ReportScope
    {
        return new ReportScope(
            1,
            [1],
            [7],
            $resources,
            new DateTimeZone('Europe/Moscow'),
        );
    }

    private function constraint(
        int $id,
        ?string $linkedType = null,
        ?int $linkedId = null,
    ): LookaheadConstraintState {
        return new LookaheadConstraintState(
            constraintId: $id,
            type: 'material',
            severity: 'hard',
            status: 'open',
            waiverUntil: null,
            waiverEvidenceRef: null,
            openedAt: new DateTimeImmutable('2026-07-01T00:00:00+03:00'),
            linkedResourceType: $linkedType,
            linkedResourceId: $linkedId,
        );
    }
}
