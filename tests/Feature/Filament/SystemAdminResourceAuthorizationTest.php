<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Filament\Resources\BlogArticleResource;
use App\Filament\Resources\BlogCategoryResource;
use App\Filament\Resources\BlogCommentResource;
use App\Filament\Resources\BlogMediaAssetResource;
use App\Filament\Resources\BlogSeoSettingsResource;
use App\Filament\Resources\BlogTagResource;
use App\Filament\Resources\ModuleResource;
use App\Filament\Resources\NotificationAnalyticsResource;
use App\Filament\Resources\NotificationResource;
use App\Filament\Resources\NotificationTemplateResource;
use App\Filament\Resources\OrganizationModuleActivationResource;
use App\Filament\Resources\OrganizationPackageSubscriptionResource;
use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\OrganizationSubscriptionResource;
use App\Filament\Resources\PaymentTransactionResource;
use App\Filament\Resources\SubscriptionPlanResource;
use App\Filament\Resources\SupportRequestResource;
use App\Filament\Resources\SystemAdminResource;
use App\Filament\Resources\UserResource;
use App\Models\Blog\BlogArticle;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Tests\TestCase;

class SystemAdminResourceAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_application_user_cannot_view_system_admin_resources(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(BlogArticleResource::canViewAny());
        $this->assertFalse(NotificationTemplateResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(OrganizationResource::canViewAny());
        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(SystemAdminResource::canViewAny());
    }

    public function test_every_resource_declares_explicit_authorization_methods(): void
    {
        foreach ($this->resourceClasses() as $resourceClass) {
            foreach (['canViewAny', 'canCreate', 'canEdit', 'canDelete', 'canDeleteAny'] as $method) {
                $reflection = new ReflectionMethod($resourceClass, $method);

                $this->assertSame(
                    $resourceClass,
                    $reflection->getDeclaringClass()->getName(),
                    "{$resourceClass} must declare {$method} instead of inheriting Filament defaults",
                );
            }
        }
    }

    public function test_content_manager_can_view_content_resources_only(): void
    {
        $this->actingAsRole('content_manager');

        $this->assertTrue(BlogArticleResource::canViewAny());
        $this->assertTrue(BlogCategoryResource::canViewAny());
        $this->assertTrue(BlogTagResource::canViewAny());
        $this->assertTrue(BlogMediaAssetResource::canViewAny());
        $this->assertTrue(BlogSeoSettingsResource::canViewAny());
        $this->assertTrue(NotificationTemplateResource::canViewAny());

        $this->assertFalse(NotificationResource::canViewAny());
        $this->assertFalse(NotificationAnalyticsResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(OrganizationResource::canViewAny());
        $this->assertFalse(OrganizationSubscriptionResource::canViewAny());
        $this->assertFalse(PaymentTransactionResource::canViewAny());
        $this->assertFalse(ModuleResource::canViewAny());
        $this->assertFalse(OrganizationModuleActivationResource::canViewAny());
        $this->assertFalse(OrganizationPackageSubscriptionResource::canViewAny());
        $this->assertFalse(SubscriptionPlanResource::canViewAny());
        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(SystemAdminResource::canViewAny());
    }

    public function test_qa_engineer_can_view_operational_read_models_without_system_admins(): void
    {
        $this->actingAsRole('qa_engineer');

        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(OrganizationResource::canViewAny());
        $this->assertTrue(OrganizationSubscriptionResource::canViewAny());
        $this->assertTrue(PaymentTransactionResource::canViewAny());
        $this->assertTrue(ModuleResource::canViewAny());
        $this->assertTrue(OrganizationModuleActivationResource::canViewAny());
        $this->assertTrue(OrganizationPackageSubscriptionResource::canViewAny());
        $this->assertTrue(SubscriptionPlanResource::canViewAny());
        $this->assertTrue(BlogArticleResource::canViewAny());
        $this->assertTrue(BlogMediaAssetResource::canViewAny());
        $this->assertTrue(NotificationResource::canViewAny());
        $this->assertTrue(NotificationAnalyticsResource::canViewAny());
        $this->assertTrue(NotificationTemplateResource::canViewAny());

        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(SystemAdminResource::canViewAny());
        $this->assertFalse(BlogCategoryResource::canViewAny());
        $this->assertFalse(BlogTagResource::canViewAny());
        $this->assertFalse(BlogSeoSettingsResource::canViewAny());
    }

    public function test_security_auditor_can_view_audit_relevant_read_models_without_media_management(): void
    {
        $this->actingAsRole('security_auditor');

        $this->assertTrue(SystemAdminResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(OrganizationResource::canViewAny());
        $this->assertTrue(OrganizationSubscriptionResource::canViewAny());
        $this->assertTrue(PaymentTransactionResource::canViewAny());
        $this->assertTrue(ModuleResource::canViewAny());
        $this->assertTrue(OrganizationModuleActivationResource::canViewAny());
        $this->assertTrue(OrganizationPackageSubscriptionResource::canViewAny());
        $this->assertTrue(SubscriptionPlanResource::canViewAny());
        $this->assertTrue(BlogArticleResource::canViewAny());
        $this->assertTrue(NotificationResource::canViewAny());
        $this->assertTrue(NotificationAnalyticsResource::canViewAny());
        $this->assertTrue(NotificationTemplateResource::canViewAny());

        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(BlogMediaAssetResource::canViewAny());
        $this->assertFalse(BlogCategoryResource::canViewAny());
        $this->assertFalse(BlogTagResource::canViewAny());
        $this->assertFalse(BlogSeoSettingsResource::canViewAny());
    }

    public function test_content_manager_create_edit_and_delete_permissions_are_resource_level(): void
    {
        $this->actingAsRole('content_manager');

        $this->assertTrue(BlogArticleResource::canCreate());
        $this->assertTrue(BlogMediaAssetResource::canCreate());
        $this->assertTrue(NotificationTemplateResource::canCreate());
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(NotificationResource::canCreate());
        $this->assertFalse(NotificationAnalyticsResource::canCreate());

        $this->assertTrue(BlogArticleResource::canEdit(new BlogArticle()));
        $this->assertTrue(BlogArticleResource::canDelete(new BlogArticle()));
        $this->assertFalse(NotificationResource::canEdit(new Notification()));
        $this->assertFalse(NotificationResource::canDelete(new Notification()));
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = SystemAdmin::factory()->role($role)->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        return $admin;
    }

    /**
     * @return list<class-string>
     */
    private function resourceClasses(): array
    {
        return [
            BlogArticleResource::class,
            BlogCategoryResource::class,
            BlogCommentResource::class,
            BlogMediaAssetResource::class,
            BlogSeoSettingsResource::class,
            BlogTagResource::class,
            NotificationAnalyticsResource::class,
            NotificationResource::class,
            NotificationTemplateResource::class,
            ModuleResource::class,
            OrganizationModuleActivationResource::class,
            OrganizationPackageSubscriptionResource::class,
            OrganizationResource::class,
            OrganizationSubscriptionResource::class,
            PaymentTransactionResource::class,
            SubscriptionPlanResource::class,
            SupportRequestResource::class,
            SystemAdminResource::class,
            UserResource::class,
        ];
    }
}
