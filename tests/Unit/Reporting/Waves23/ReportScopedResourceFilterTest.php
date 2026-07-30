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
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 4).'/app/Support/Reporting/ReportScopedResourceFilter.php';
    }

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

    #[Test]
    public function row_must_satisfy_every_active_applicable_resource_dimension(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [7],
            [
                new ReportScopedResource('task', 41, 7),
                new ReportScopedResource('constraint', 51, 7),
                new ReportScopedResource('purchase_request', 61, 7),
            ],
            new DateTimeZone('Europe/Moscow'),
        );
        $filter = new ReportScopedResourceFilter;
        $applicableKinds = [
            'task',
            'schedule_task',
            'constraint',
            'work_constraint',
            'purchase_request',
        ];

        self::assertTrue($filter->allowsReferences($scope, 7, [
            ['type' => 'schedule_task', 'id' => 41, 'project_id' => 7],
            ['type' => 'work_constraint', 'id' => 51, 'project_id' => 7],
            ['type' => 'purchase_request', 'id' => 61, 'project_id' => 7],
        ], $applicableKinds));
        self::assertFalse($filter->allowsReferences($scope, 7, [
            ['type' => 'schedule_task', 'id' => 41, 'project_id' => 7],
            ['type' => 'work_constraint', 'id' => 51, 'project_id' => 7],
        ], $applicableKinds));
        self::assertFalse($filter->allowsReferences($scope, 7, [
            ['type' => 'schedule_task', 'id' => 41, 'project_id' => 7],
            ['type' => 'work_constraint', 'id' => 51, 'project_id' => 7],
            ['type' => 'purchase_request', 'id' => 62, 'project_id' => 7],
        ], $applicableKinds));
    }

    #[Test]
    public function active_kind_restricted_to_another_project_denies_every_row_in_selected_project(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [7, 8],
            [new ReportScopedResource('constraint', 51, 7)],
            new DateTimeZone('Europe/Moscow'),
        );

        self::assertFalse((new ReportScopedResourceFilter)->allowsReferences(
            $scope,
            8,
            [
                ['type' => 'schedule_task', 'id' => 41, 'project_id' => 8],
                ['type' => 'work_constraint', 'id' => 51, 'project_id' => 8],
            ],
            ['task', 'schedule_task', 'constraint', 'work_constraint'],
        ));
    }
}
