<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\Support\Reporting\ReportScopedResourceFilter;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportScopedResourceFilterTest extends TestCase
{
    #[Test]
    public function matching_resource_kinds_restrict_materialized_ids_by_project(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [7, 8],
            [
                new ReportScopedResource('schedule_task', 41, 7),
                new ReportScopedResource('task', 42, 8),
                new ReportScopedResource('constraint', 51, 7),
            ],
            new DateTimeZone('Europe/Moscow'),
        );

        $filter = new ReportScopedResourceFilter;

        self::assertSame([41], $filter->ids($scope, ['task', 'schedule_task'], [7]));
        self::assertSame([41, 42], $filter->ids($scope, ['task', 'schedule_task'], [7, 8]));
        self::assertSame([51], $filter->ids($scope, ['constraint', 'work_constraint'], [7]));
    }

    #[Test]
    public function absent_resource_kind_leaves_that_dimension_unrestricted(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [7],
            [new ReportScopedResource('constraint', 51, 7)],
            new DateTimeZone('Europe/Moscow'),
        );

        self::assertNull((new ReportScopedResourceFilter)->ids(
            $scope,
            ['task', 'schedule_task'],
            [7],
        ));
    }

    #[Test]
    public function resource_kind_present_only_in_another_project_is_fail_closed(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [7, 8],
            [new ReportScopedResource('task', 41, 7)],
            new DateTimeZone('Europe/Moscow'),
        );

        self::assertSame([], (new ReportScopedResourceFilter)->ids(
            $scope,
            ['task', 'schedule_task'],
            [8],
        ));
    }
}
