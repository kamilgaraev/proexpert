<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\Support\Reporting\ReportSourceObjectAccessAuthorizer;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportSourceObjectAccessAuthorizerTest extends TestCase
{
    #[Test]
    public function exact_task_restriction_allows_only_the_authorized_drilldown_source(): void
    {
        $authorizer = new ReportSourceObjectAccessAuthorizer;
        $context = $this->context([new ReportScopedResource('task', 41, 7)]);

        $authorizer->assertAccessible($context, 'schedule_task', 41, 7);
        self::addToAssertionCount(1);

        $this->expectException(ReportContractException::class);
        $authorizer->assertAccessible($context, 'schedule_task', 42, 7);
    }

    #[Test]
    public function foreign_project_source_is_denied_without_existence_disclosure(): void
    {
        $this->expectException(ReportContractException::class);

        (new ReportSourceObjectAccessAuthorizer)->assertAccessible(
            $this->context([]),
            'performance_act',
            9,
            8,
        );
    }

    #[Test]
    public function row_and_export_source_references_are_checked_as_one_fail_closed_fence(): void
    {
        $authorizer = new ReportSourceObjectAccessAuthorizer;
        $context = $this->context([
            new ReportScopedResource('task', 41, 7),
            new ReportScopedResource('constraint', 51, 7),
        ]);

        $authorizer->assertReferencesAccessible($context, [
            ['type' => 'schedule_task', 'id' => 41, 'project_id' => 7],
            ['type' => 'work_constraint', 'id' => 51, 'project_id' => 7],
        ]);
        self::addToAssertionCount(1);

        $this->expectException(ReportContractException::class);
        $authorizer->assertReferencesAccessible($context, [
            ['type' => 'schedule_task', 'id' => 42, 'project_id' => 7],
            ['type' => 'work_constraint', 'id' => 51, 'project_id' => 7],
        ]);
    }

    #[Test]
    public function malformed_row_source_reference_is_denied(): void
    {
        $this->expectException(ReportContractException::class);

        (new ReportSourceObjectAccessAuthorizer)->assertReferencesAccessible(
            $this->context([]),
            [['type' => 'schedule_task', 'id' => null, 'project_id' => 7]],
        );
    }

    #[Test]
    public function row_without_source_references_is_denied(): void
    {
        $this->expectException(ReportContractException::class);

        (new ReportSourceObjectAccessAuthorizer)->assertReferencesAccessible(
            $this->context([]),
            [],
        );
    }

    private function context(array $resources): ReportExecutionContext
    {
        $timezone = new DateTimeZone('Europe/Moscow');
        $scope = new ReportScope(3, [3], [7], $resources, $timezone);

        return new ReportExecutionContext(
            new ReportActor(5, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, true, true, true, false, true, false),
            new AuthorizationDecisionContext(
                'queue',
                3,
                [3],
                [7],
                $resources,
                $timezone,
                'source-abac-test',
                null,
            ),
        );
    }
}
