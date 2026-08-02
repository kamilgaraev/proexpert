<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceObjectReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportSourceObjectAuthorizerObjectivePermissionsTest extends TestCase
{
    #[DataProvider('sources')]
    public function test_profile_permission_cannot_replace_underlying_domain_permission(
        string $sourceType,
        string $domainPermission,
    ): void {
        $actor = (new User)->forceFill(['id' => 17]);
        $actor->exists = true;
        $project = (new Project)->forceFill(['id' => 101, 'organization_id' => 10]);
        $project->exists = true;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturnCallback(
            static fn (User $user, string $permission): bool => $permission
                === 'contractor_marketplace.profile.view',
        );
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->method('canAccessProject')->willReturn(true);
        $sources = $this->createMock(ReportSourceObjectReader::class);
        $sources->method('actor')->willReturn($actor);
        $sources->method('project')->willReturn($project);
        $sources->expects(self::never())->method('exists');
        $authorizer = new ReportSourceObjectAuthorizer($authorization, $projectAccess, $sources);

        self::assertSame(
            'forbidden',
            $authorizer->availability(
                $this->context([
                    'contractor_marketplace.profile.view',
                    $domainPermission,
                ]),
                $sourceType,
                7331,
                10,
                101,
            ),
        );
    }

    public static function sources(): array
    {
        return [
            'R06 schedule' => ['baseline_schedule_variance', 'schedule.view'],
            'R17 procurement' => ['supply_reliability', 'procurement.purchase_orders.view'],
            'R23 quality' => ['quality_defect_flow', 'quality-control.defects.view'],
            'R24 safety' => ['safety_incident_actions', 'safety-management.view'],
        ];
    }

    private function context(array $permissions): ReportExecutionContext
    {
        $timezone = new DateTimeZone('Europe/Moscow');

        return new ReportExecutionContext(
            new ReportActor(17, 'active', $permissions),
            new ReportScope(10, [10], [101], [], $timezone),
            new ReportVisibility(true, true, false, false, false, false, false),
            new AuthorizationDecisionContext(
                'http',
                10,
                [10],
                [101],
                [],
                $timezone,
                'objective-source-permissions',
                null,
            ),
        );
    }
}
