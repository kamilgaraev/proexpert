<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\BusinessModules\Features\QualityControl\Services\QualityProjectIssueNotificationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueNotificationsTest extends TestCase
{
    public function test_shared_workflow_notifies_assignment_review_return_and_acceptance_without_quality_subscription(): void
    {
        [$context, $project, $recipient, $package] = $this->fixture();
        $this->allow(true, false, true);
        $events = [];
        $this->mock(NotificationService::class)->shouldReceive('send')->times(5)
            ->andReturnUsing(static function (User $user, string $type, array $data) use (&$events): Notification {
                $events[] = [$user->id, $type, $data];
                return new Notification();
            });
        $pir = app(DesignProjectIssueService::class);
        $quality = app(QualityDefectService::class);
        $issue = $pir->create($context->user, $project->organization_id, $project->id, ['package_id' => $package->id, 'title' => 'Проверить решение', 'severity' => 'major', 'assignee_id' => $recipient->id]);
        $id = $issue->id;
        $issue = $quality->resolve($issue, $recipient->id, ['comment' => 'Исправлено']);
        $issue = $pir->verify($issue, $context->user, false, 'Требуется доработка');
        $issue = $quality->resolve($issue, $recipient->id, ['comment' => 'Доработано']);
        $issue = $pir->verify($issue, $context->user, true, 'Принято');

        self::assertSame($id, $issue->id);
        self::assertSame('resolved', $issue->status->value);
        self::assertSame([$recipient->id, $context->user->id, $recipient->id, $context->user->id, $recipient->id], array_column($events, 0));
        self::assertSame(['design_issue.assigned', 'design_issue.ready_for_review', 'design_issue.rejected', 'design_issue.ready_for_review', 'design_issue.resolved'], array_column($events, 1));
        self::assertCount(5, array_unique(array_column(array_column($events, 2), 'transition_id')));
        foreach ($events as [, , $data]) {
            self::assertSame($id, $data['entity_id']);
            self::assertSame('/pir/packages/'.$package->id, $data['target_route']);
        }
    }

    public static function recipientAccess(): array
    {
        return [
            'PIR only' => [true, false, true, true, true],
            'quality only' => [false, true, true, true, true],
            'both modules' => [true, true, true, true, true],
            'neither module' => [false, false, true, true, false],
            'no permission' => [true, true, false, true, false],
            'membership revoked' => [true, true, true, false, false],
        ];
    }

    #[DataProvider('recipientAccess')]
    public function test_notifications_require_an_available_interface_and_recipient_project_access(bool $pir, bool $quality, bool $permission, bool $member, bool $expected): void
    {
        [$context, $project, $recipient, $package] = $this->fixture();
        $this->allow($pir, $quality, $permission);
        if (! $member) $project->users()->updateExistingPivot($recipient->id, ['is_active' => false]);
        $issue = QualityDefect::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'kind' => 'project', 'created_by' => $context->user->id, 'assigned_to' => $recipient->id, 'defect_number' => 'PIR-NOTIFY', 'title' => 'Проектное замечание', 'status' => 'assigned', 'metadata' => ['design_issue_context' => ['package_id' => $package->id]]]);
        $history = $issue->statusHistory()->create(['organization_id' => $project->organization_id, 'to_status' => 'assigned', 'changed_by' => $context->user->id, 'changed_at' => now()]);
        $notifications = $this->mock(NotificationService::class);
        if ($expected) {
            $notifications->shouldReceive('send')->once()->withArgs(static function (User $user, string $type, array $data) use ($recipient, $pir, $package, $issue): bool {
                return $user->id === $recipient->id && $type === 'design_issue.assigned'
                    && $data['target_route'] === ($pir ? '/pir/packages/'.$package->id : '/quality-control/defects/'.$issue->id);
            })->andReturn(new Notification());
        } else {
            $notifications->shouldNotReceive('send');
        }

        app(QualityProjectIssueNotificationService::class)->statusChanged($issue, $history);
    }

    private function allow(bool $pir, bool $quality, bool $permission): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturn($permission);
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(static fn (int $organizationId, string $module): bool => match ($module) {
            'design-management' => $pir, 'quality-control' => $quality, default => false,
        });
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $recipient = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($recipient->id, ['is_active' => true]);
        foreach ([$context->user, $recipient] as $user) $project->users()->attach($user->id, ['is_active' => true, 'role' => 'project_manager']);
        $package = DesignPackage::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $context->user->id, 'updated_by' => $context->user->id, 'title' => 'АР', 'project_stage' => 'pd', 'status' => 'draft']);
        return [$context, $project, $recipient, $package];
    }
}
