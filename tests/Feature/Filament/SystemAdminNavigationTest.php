<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
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
use App\Filament\Support\NavigationGroups;
use App\Models\SystemAdmin;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class SystemAdminNavigationTest extends TestCase
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

    public function test_navigation_groups_are_centralized_and_ordered_for_operator_workflows(): void
    {
        self::assertSame([
            'Обзор',
            'Платформа',
            'Организации',
            'Биллинг',
            'Пользователи',
            'Блог CMS',
            'База знаний',
            'AI-сметчик',
            'Поддержка',
            'Уведомления',
            'Аудит',
            'Настройки',
        ], NavigationGroups::orderedLabels());
    }

    public function test_dashboard_navigation_is_grouped_before_operational_resources(): void
    {
        self::assertSame(NavigationGroups::dashboard(), Dashboard::getNavigationGroup());
        self::assertSame(10, Dashboard::getNavigationSort());
        self::assertSame('heroicon-o-chart-pie', $this->navigationIconFor(Dashboard::class));
    }

    public function test_ai_estimator_pages_use_one_ordered_navigation_group(): void
    {
        self::assertSame(NavigationGroups::aiEstimator(), EstimateGenerationDashboard::getNavigationGroup());
        self::assertSame(1, EstimateGenerationDashboard::getNavigationSort());
        self::assertSame('heroicon-o-presentation-chart-line', $this->navigationIconFor(EstimateGenerationDashboard::class));

        self::assertSame(NavigationGroups::aiEstimator(), EstimateGenerationSettings::getNavigationGroup());
        self::assertSame(12, EstimateGenerationSettings::getNavigationSort());
        self::assertSame('heroicon-o-adjustments-horizontal', $this->navigationIconFor(EstimateGenerationSettings::class));
    }

    public function test_panel_navigation_groups_do_not_duplicate_resource_icons(): void
    {
        foreach (NavigationGroups::panelGroups() as $group) {
            self::assertNull($group->getIcon(), (string) $group->getLabel());
        }
    }

    public function test_resource_navigation_uses_expected_groups_sort_order_and_icons(): void
    {
        foreach ($this->expectedResourceNavigation() as $resourceClass => $expected) {
            self::assertSame($expected['group'], $resourceClass::getNavigationGroup(), $resourceClass);
            self::assertSame($expected['sort'], $resourceClass::getNavigationSort(), $resourceClass);
            self::assertSame($expected['icon'], $this->navigationIconFor($resourceClass), $resourceClass);
        }
    }

    public function test_navigation_registration_is_hidden_when_role_cannot_view_resource(): void
    {
        $this->actingAsRole('content_manager');

        self::assertTrue(BlogArticleResource::shouldRegisterNavigation());
        self::assertTrue(NotificationTemplateResource::shouldRegisterNavigation());
        self::assertFalse(PaymentTransactionResource::shouldRegisterNavigation());
        self::assertFalse(ActivityEventResource::shouldRegisterNavigation());

        self::assertFalse(EstimateGenerationDashboard::canAccess());
        self::assertFalse(SessionResource::shouldRegisterNavigation());
        self::assertFalse(TrainingDatasetResource::shouldRegisterNavigation());

        $this->auth->logout();
        $this->actingAsRole('support_viewer');

        self::assertTrue(SupportRequestResource::shouldRegisterNavigation());
        self::assertFalse(BlogArticleResource::shouldRegisterNavigation());
        self::assertFalse(NotificationTemplateResource::shouldRegisterNavigation());
        self::assertFalse(ActivityEventResource::shouldRegisterNavigation());

        $this->auth->logout();
        $this->actingAsRole('support_operator');

        self::assertTrue(EstimateGenerationDashboard::canAccess());
        self::assertTrue(SessionResource::shouldRegisterNavigation());
        self::assertTrue(UsageResource::shouldRegisterNavigation());
        self::assertTrue(FailureResource::shouldRegisterNavigation());
        self::assertTrue(PipelineCheckpointResource::shouldRegisterNavigation());
        self::assertFalse(TrainingDatasetResource::shouldRegisterNavigation());
        self::assertFalse(BenchmarkRunResource::shouldRegisterNavigation());
        self::assertFalse(EstimateGenerationSettings::canAccess());
    }

    /**
     * @return array<class-string, array{group: string, sort: int, icon: string}>
     */
    private function expectedResourceNavigation(): array
    {
        return [
            ModuleResource::class => [
                'group' => NavigationGroups::platform(),
                'sort' => 10,
                'icon' => 'heroicon-o-squares-2x2',
            ],
            OrganizationModuleActivationResource::class => [
                'group' => NavigationGroups::platform(),
                'sort' => 20,
                'icon' => 'heroicon-o-puzzle-piece',
            ],
            OrganizationPackageSubscriptionResource::class => [
                'group' => NavigationGroups::platform(),
                'sort' => 30,
                'icon' => 'heroicon-o-archive-box',
            ],
            ApplicationErrorResource::class => [
                'group' => NavigationGroups::platform(),
                'sort' => 40,
                'icon' => 'heroicon-o-bug-ant',
            ],
            OrganizationResource::class => [
                'group' => NavigationGroups::organizations(),
                'sort' => 10,
                'icon' => 'heroicon-o-building-office-2',
            ],
            SubscriptionPlanResource::class => [
                'group' => NavigationGroups::billing(),
                'sort' => 10,
                'icon' => 'heroicon-o-credit-card',
            ],
            OrganizationSubscriptionResource::class => [
                'group' => NavigationGroups::billing(),
                'sort' => 20,
                'icon' => 'heroicon-o-credit-card',
            ],
            PaymentTransactionResource::class => [
                'group' => NavigationGroups::billing(),
                'sort' => 30,
                'icon' => 'heroicon-o-banknotes',
            ],
            UserResource::class => [
                'group' => NavigationGroups::users(),
                'sort' => 10,
                'icon' => 'heroicon-o-users',
            ],
            BlogArticleResource::class => [
                'group' => NavigationGroups::blog(),
                'sort' => 10,
                'icon' => 'heroicon-o-document-text',
            ],
            BlogCategoryResource::class => [
                'group' => NavigationGroups::blog(),
                'sort' => 20,
                'icon' => 'heroicon-o-folder',
            ],
            BlogTagResource::class => [
                'group' => NavigationGroups::blog(),
                'sort' => 30,
                'icon' => 'heroicon-o-hashtag',
            ],
            BlogMediaAssetResource::class => [
                'group' => NavigationGroups::blog(),
                'sort' => 40,
                'icon' => 'heroicon-o-photo',
            ],
            BlogCommentResource::class => [
                'group' => NavigationGroups::blog(),
                'sort' => 50,
                'icon' => 'heroicon-o-chat-bubble-left-right',
            ],
            BlogSeoSettingsResource::class => [
                'group' => NavigationGroups::blog(),
                'sort' => 60,
                'icon' => 'heroicon-o-globe-alt',
            ],
            SupportRequestResource::class => [
                'group' => NavigationGroups::support(),
                'sort' => 10,
                'icon' => 'heroicon-o-lifebuoy',
            ],
            NotificationTemplateResource::class => [
                'group' => NavigationGroups::notifications(),
                'sort' => 10,
                'icon' => 'heroicon-o-envelope-open',
            ],
            NotificationResource::class => [
                'group' => NavigationGroups::notifications(),
                'sort' => 20,
                'icon' => 'heroicon-o-bell-alert',
            ],
            NotificationAnalyticsResource::class => [
                'group' => NavigationGroups::notifications(),
                'sort' => 30,
                'icon' => 'heroicon-o-chart-bar-square',
            ],
            ActivityEventResource::class => [
                'group' => NavigationGroups::audit(),
                'sort' => 10,
                'icon' => 'heroicon-o-shield-check',
            ],
            SystemAdminResource::class => [
                'group' => NavigationGroups::settings(),
                'sort' => 10,
                'icon' => 'heroicon-o-shield-check',
            ],
            SessionResource::class => [
                'group' => NavigationGroups::aiEstimator(),
                'sort' => 2,
                'icon' => 'heroicon-o-command-line',
            ],
            UsageResource::class => [
                'group' => NavigationGroups::aiEstimator(),
                'sort' => 3,
                'icon' => 'heroicon-o-banknotes',
            ],
            FailureResource::class => [
                'group' => NavigationGroups::aiEstimator(),
                'sort' => 4,
                'icon' => 'heroicon-o-exclamation-triangle',
            ],
            PipelineCheckpointResource::class => [
                'group' => NavigationGroups::aiEstimator(),
                'sort' => 5,
                'icon' => 'heroicon-o-queue-list',
            ],
            TrainingDatasetResource::class => [
                'group' => NavigationGroups::aiEstimator(),
                'sort' => 10,
                'icon' => 'heroicon-o-academic-cap',
            ],
            BenchmarkRunResource::class => [
                'group' => NavigationGroups::aiEstimator(),
                'sort' => 11,
                'icon' => 'heroicon-o-beaker',
            ],
        ];
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

    private function navigationIconFor(string $class): string
    {
        $property = new ReflectionProperty($class, 'navigationIcon');
        $property->setAccessible(true);

        $value = $property->getValue();

        self::assertIsString($value, "{$class} navigation icon must be a Heroicon component string.");

        return $value;
    }
}
