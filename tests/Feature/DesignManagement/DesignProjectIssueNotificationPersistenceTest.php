<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueNotificationPersistenceTest extends TestCase
{
    public function test_notification_and_shared_issue_commit_or_rollback_together(): void
    {
        Queue::fake();
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->mock(AccessController::class)->shouldReceive('hasModuleAccess')->andReturnUsing(static fn (int $organizationId, string $module): bool => $module === 'design-management');
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $recipient = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($recipient->id, ['is_active' => true]);
        $project->users()->attach($recipient->id, ['is_active' => true, 'role' => 'project_manager']);
        $service = app(QualityDefectService::class);
        $payload = ['project_id' => $project->id, 'kind' => 'project', 'assigned_to' => $recipient->id, 'title' => 'Уведомление вместе с замечанием', 'severity' => 'major', 'inspection_required' => false];
        $issue = $service->create($project->organization_id, $context->user->id, $payload);
        $notifications = Notification::query()->where('organization_id', $project->organization_id)->where('type', 'design_issue.assigned')->where('notifiable_id', $recipient->id);
        self::assertSame(1, $notifications->count());
        $notification = $notifications->firstOrFail();
        self::assertSame($issue->id, $notification->data['entity_id']);
        self::assertSame('Вам назначено проектное замечание', $notification->data['title']);
        self::assertSame([NotificationInterface::Admin], $notification->targets()->pluck('interface')->all());

        $rolledBackId = null;
        try {
            DB::transaction(function () use ($service, $project, $context, $payload, &$rolledBackId): void {
                $created = $service->create($project->organization_id, $context->user->id, $payload);
                $rolledBackId = $created->id;
                throw new RuntimeException('rollback_test');
            });
            self::fail('Expected rollback');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback_test', $exception->getMessage());
        }

        self::assertNotNull($rolledBackId);
        self::assertFalse(QualityDefect::query()->whereKey($rolledBackId)->exists());
        self::assertSame(1, $notifications->count());
    }
}
