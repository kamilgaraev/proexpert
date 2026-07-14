<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Filament\Pages\EstimateGeneration\EstimateGenerationDashboard;
use App\Filament\Pages\EstimateGeneration\EstimateGenerationSettings;
use App\Filament\Resources\ActivityEventResource;
use App\Filament\Resources\BlogArticleResource;
use App\Filament\Resources\BlogCategoryResource;
use App\Filament\Resources\BlogCommentResource;
use App\Filament\Resources\BlogMediaAssetResource;
use App\Filament\Resources\BlogSeoSettingsResource;
use App\Filament\Resources\BlogTagResource;
use App\Filament\Resources\EstimateGeneration\BenchmarkRunResource;
use App\Filament\Resources\EstimateGeneration\FailureResource;
use App\Filament\Resources\EstimateGeneration\PipelineCheckpointResource;
use App\Filament\Resources\EstimateGeneration\SessionResource;
use App\Filament\Resources\EstimateGeneration\TrainingDatasetResource;
use App\Filament\Resources\EstimateGeneration\UsageResource;
use App\Filament\Resources\ModuleResource;
use App\Filament\Resources\Monitoring\ApplicationErrorResource;
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
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SystemAdminResourceAuthorizationTest extends TestCase
{
    private object $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(new Filesystem, dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang');
        $container->instance('translator', new Translator($loader, 'ru'));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        });

        $this->auth = new class
        {
            public ?SystemAdmin $user = null;

            public function guard(?string $name = null): self
            {
                return $this;
            }

            public function user(): ?SystemAdmin
            {
                return $this->user;
            }

            public function logout(): void
            {
                $this->user = null;
            }
        };
        $container->instance('auth', $this->auth);
        $container->instance(SystemAdminRoleService::class, $this->roleService());

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_application_user_cannot_view_system_admin_resources(): void
    {
        $this->assertFalse(BlogArticleResource::canViewAny());
        $this->assertFalse(ActivityEventResource::canViewAny());
        $this->assertFalse(NotificationTemplateResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(OrganizationResource::canViewAny());
        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(SystemAdminResource::canViewAny());
        $this->assertFalse(EstimateGenerationDashboard::canAccess());
        $this->assertFalse(SessionResource::canViewAny());
        $this->assertFalse(UsageResource::canViewAny());
        $this->assertFalse(FailureResource::canViewAny());
        $this->assertFalse(PipelineCheckpointResource::canViewAny());
        $this->assertFalse(TrainingDatasetResource::canViewAny());
        $this->assertFalse(BenchmarkRunResource::canViewAny());
        $this->assertFalse(EstimateGenerationSettings::canAccess());
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
        $this->assertFalse(ActivityEventResource::canViewAny());
        $this->assertFalse(ApplicationErrorResource::canViewAny());
        $this->assertFalse(EstimateGenerationDashboard::canAccess());
        $this->assertFalse(SessionResource::canViewAny());
        $this->assertFalse(TrainingDatasetResource::canViewAny());
        $this->assertFalse(BenchmarkRunResource::canViewAny());
        $this->assertFalse(EstimateGenerationSettings::canAccess());
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
        $this->assertTrue(ApplicationErrorResource::canViewAny());
        $this->assertTrue(EstimateGenerationDashboard::canAccess());
        $this->assertTrue(SessionResource::canViewAny());
        $this->assertTrue(UsageResource::canViewAny());
        $this->assertTrue(FailureResource::canViewAny());
        $this->assertTrue(PipelineCheckpointResource::canViewAny());
        $this->assertTrue(TrainingDatasetResource::canViewAny());
        $this->assertTrue(BenchmarkRunResource::canViewAny());

        $this->assertFalse(ActivityEventResource::canViewAny());
        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(SystemAdminResource::canViewAny());
        $this->assertFalse(BlogCategoryResource::canViewAny());
        $this->assertFalse(BlogTagResource::canViewAny());
        $this->assertFalse(BlogSeoSettingsResource::canViewAny());
        $this->assertFalse(EstimateGenerationSettings::canAccess());
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
        $this->assertTrue(ApplicationErrorResource::canViewAny());
        $this->assertTrue(EstimateGenerationDashboard::canAccess());
        $this->assertTrue(SessionResource::canViewAny());
        $this->assertTrue(UsageResource::canViewAny());
        $this->assertTrue(FailureResource::canViewAny());
        $this->assertTrue(PipelineCheckpointResource::canViewAny());

        $this->assertFalse(SupportRequestResource::canViewAny());
        $this->assertFalse(BlogMediaAssetResource::canViewAny());
        $this->assertFalse(BlogCategoryResource::canViewAny());
        $this->assertFalse(BlogTagResource::canViewAny());
        $this->assertFalse(BlogSeoSettingsResource::canViewAny());
        $this->assertFalse(TrainingDatasetResource::canViewAny());
        $this->assertFalse(BenchmarkRunResource::canViewAny());
        $this->assertFalse(EstimateGenerationSettings::canAccess());
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

        $this->assertTrue(BlogArticleResource::canEdit(new BlogArticle));
        $this->assertTrue(BlogArticleResource::canDelete(new BlogArticle));
        $this->assertFalse(NotificationResource::canEdit(new Notification));
        $this->assertFalse(NotificationResource::canDelete(new Notification));
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = new SystemAdmin([
            'role' => $role,
            'is_active' => true,
        ]);
        $this->auth->user = $admin;

        return $admin;
    }

    private function roleService(): SystemAdminRoleService
    {
        $rolesPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR
            .'RoleDefinitions'.DIRECTORY_SEPARATOR.'system_admin';

        return new class($rolesPath) extends SystemAdminRoleService
        {
            public function __construct(private readonly string $rolesPath) {}

            public function hasPermission(SystemAdmin $systemAdmin, string $permission): bool
            {
                if (! $systemAdmin->isActive()) {
                    return false;
                }

                $contents = file_get_contents($this->rolesPath.DIRECTORY_SEPARATOR.$systemAdmin->role.'.json');
                if (! is_string($contents)) {
                    return false;
                }

                $definition = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $permissions = array_merge(
                    $definition['system_permissions'] ?? [],
                    ...array_values($definition['module_permissions'] ?? []),
                );

                return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
            }

            public function clearCache(): void {}
        };
    }

    /**
     * @return list<class-string>
     */
    private function resourceClasses(): array
    {
        return [
            BlogArticleResource::class,
            ActivityEventResource::class,
            BlogCategoryResource::class,
            BlogCommentResource::class,
            BlogMediaAssetResource::class,
            BlogSeoSettingsResource::class,
            BlogTagResource::class,
            NotificationAnalyticsResource::class,
            NotificationResource::class,
            NotificationTemplateResource::class,
            ModuleResource::class,
            ApplicationErrorResource::class,
            OrganizationModuleActivationResource::class,
            OrganizationPackageSubscriptionResource::class,
            OrganizationResource::class,
            OrganizationSubscriptionResource::class,
            PaymentTransactionResource::class,
            SubscriptionPlanResource::class,
            SupportRequestResource::class,
            SystemAdminResource::class,
            UserResource::class,
            SessionResource::class,
            UsageResource::class,
            FailureResource::class,
            PipelineCheckpointResource::class,
        ];
    }
}
