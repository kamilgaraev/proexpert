<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Jobs\SendNotificationJob;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestNotificationService;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class SiteRequestMobileRecipientTest extends TestCase
{
    public function test_project_member_with_mobile_permissions_receives_new_request_without_owner_role(): void
    {
        $organization = Organization::factory()->verified()->create();
        $this->ensureModule('site-requests', [
            'site_requests.view',
            'site_requests.edit',
        ]);
        $this->ensureModule('notifications', [
            'notifications.receive.site_requests',
        ]);
        $this->subscribeToPackageIncludingSiteRequests($organization->id);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $recipient = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($recipient->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            $recipient,
            'foreman',
            AuthorizationContext::getProjectContext($project->id, $organization->id),
        );
        $inactiveMember = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($inactiveMember->id, [
            'is_owner' => false,
            'is_active' => false,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            $inactiveMember,
            'foreman',
            AuthorizationContext::getProjectContext($project->id, $organization->id),
        );
        $otherProject = Project::factory()->create(['organization_id' => $organization->id]);
        $otherProjectMember = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($otherProjectMember->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            $otherProjectMember,
            'foreman',
            AuthorizationContext::getProjectContext($otherProject->id, $organization->id),
        );
        $request = SiteRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $recipient->id,
            'title' => 'Нужна доставка материала',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Бетон',
            'material_quantity' => 2,
            'material_unit' => 'м³',
        ]);
        $module = Mockery::mock(SiteRequestsModule::class);
        $module->shouldReceive('getSettings')->andReturn(['notify_on_create' => true]);
        $module->shouldReceive('hasNotifications')->andReturn(true);
        Queue::fake();

        $notifications = app(SiteRequestNotificationService::class, ['module' => $module]);
        $notifications->notifyOnCreated($request);

        $notification = Notification::query()
            ->where('type', 'site_request_created')
            ->firstOrFail();

        self::assertSame($recipient->id, (int) $notification->notifiable_id);
        self::assertSame(1, Notification::query()->where('type', 'site_request_created')->count());
        self::assertTrue($notification->targets()->where('interface', NotificationInterface::Mobile->value)->exists());
        Queue::assertPushed(SendNotificationJob::class);
    }

    public function test_admin_notification_remains_when_mobile_interface_access_is_absent(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, ['is_owner' => false, 'is_active' => true, 'settings' => null]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Администратор интерфейса',
            'slug' => 'admin_interface_'.$organization->id,
            'system_permissions' => [],
            'module_permissions' => [],
            'interface_access' => ['admin'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_slug' => $role->slug,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext($organization->id)->id,
            'is_active' => true,
        ]);
        Queue::fake();

        $notification = app(\App\BusinessModules\Features\Notifications\Services\NotificationService::class)->send(
            $user,
            'mobile_announcement',
            ['title' => 'Объявление', 'message' => 'Информация'],
            'marketing',
            channels: ['in_app'],
            organizationId: $organization->id,
            requiredPermissions: [],
            interfaces: ['admin', 'mobile'],
        );

        self::assertTrue($notification->targets()->where('interface', NotificationInterface::Admin->value)->exists());
        self::assertFalse($notification->targets()->where('interface', NotificationInterface::Mobile->value)->exists());
    }

    public function test_mobile_only_target_is_not_persisted_for_user_without_mobile_interface_access(): void
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);

        $notification = app(\App\BusinessModules\Features\Notifications\Services\NotificationService::class)->send(
            $user,
            'mobile_announcement',
            ['title' => 'Объявление', 'message' => 'Информация'],
            'marketing',
            interfaces: ['mobile'],
            organizationId: $organization->id,
        );

        self::assertFalse($notification->exists);
        self::assertSame([], $notification->channels);
        self::assertSame(0, NotificationTarget::query()->count());
    }

    private function ensureModule(string $slug, array $permissions): void
    {
        $module = Module::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $slug,
                'version' => '1.0.0',
                'type' => 'feature',
                'billing_model' => 'free',
                'category' => 'test',
                'permissions' => $permissions,
                'is_active' => true,
                'is_system_module' => false,
            ]
        );
        $module->forceFill(['permissions' => $permissions, 'is_active' => true])->save();
    }

    private function subscribeToPackageIncludingSiteRequests(int $organizationId): void
    {
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organizationId,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'auto_renew_enabled' => true,
        ]);

        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organizationId,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 0,
            'current_period_start_at' => now()->subDay(),
            'current_period_end_at' => now()->addDays(30),
        ]);
    }
}
