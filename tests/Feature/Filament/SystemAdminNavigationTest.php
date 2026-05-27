<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ActivityEventResource;
use App\Filament\Resources\BlogArticleResource;
use App\Filament\Resources\BlogCategoryResource;
use App\Filament\Resources\BlogCommentResource;
use App\Filament\Resources\BlogMediaAssetResource;
use App\Filament\Resources\BlogSeoSettingsResource;
use App\Filament\Resources\BlogTagResource;
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
use Illuminate\Support\Facades\Auth;
use ReflectionProperty;
use Tests\TestCase;

final class SystemAdminNavigationTest extends TestCase
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

    public function test_navigation_groups_are_centralized_and_ordered_for_operator_workflows(): void
    {
        self::assertSame([
            'Обзор',
            'Платформа',
            'Организации',
            'Биллинг',
            'Пользователи',
            'Блог CMS',
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

        Auth::guard('system_admin')->logout();
        $this->actingAsRole('support_viewer');

        self::assertTrue(SupportRequestResource::shouldRegisterNavigation());
        self::assertFalse(BlogArticleResource::shouldRegisterNavigation());
        self::assertFalse(NotificationTemplateResource::shouldRegisterNavigation());
        self::assertFalse(ActivityEventResource::shouldRegisterNavigation());
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
        ];
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = SystemAdmin::factory()->role($role)->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        return $admin;
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
