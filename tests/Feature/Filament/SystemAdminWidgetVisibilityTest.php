<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Widgets\NotificationDeliveryStatsWidget;
use App\Filament\Widgets\SaaSIncomeStatsWidget;
use App\Filament\Widgets\SubscriptionPlanStatsWidget;
use App\Filament\Widgets\UsersStatsWidget;
use App\Models\SystemAdmin;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SystemAdminWidgetVisibilityTest extends TestCase
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

    public function test_super_admin_can_view_all_dashboard_widgets(): void
    {
        $this->actingAsRole('super_admin');

        $this->assertTrue(UsersStatsWidget::canView());
        $this->assertTrue(SaaSIncomeStatsWidget::canView());
        $this->assertTrue(SubscriptionPlanStatsWidget::canView());
        $this->assertTrue(NotificationDeliveryStatsWidget::canView());
    }

    public function test_content_manager_cannot_view_platform_metric_widgets(): void
    {
        $this->actingAsRole('content_manager');

        $this->assertFalse(UsersStatsWidget::canView());
        $this->assertFalse(SaaSIncomeStatsWidget::canView());
        $this->assertFalse(SubscriptionPlanStatsWidget::canView());
        $this->assertFalse(NotificationDeliveryStatsWidget::canView());
    }

    public function test_qa_engineer_can_view_non_revenue_platform_metric_widgets(): void
    {
        $this->actingAsRole('qa_engineer');

        $this->assertTrue(UsersStatsWidget::canView());
        $this->assertFalse(SaaSIncomeStatsWidget::canView());
        $this->assertTrue(SubscriptionPlanStatsWidget::canView());
        $this->assertTrue(NotificationDeliveryStatsWidget::canView());
    }

    public function test_security_auditor_can_view_read_only_platform_metric_widgets_without_revenue(): void
    {
        $this->actingAsRole('security_auditor');

        $this->assertTrue(UsersStatsWidget::canView());
        $this->assertFalse(SaaSIncomeStatsWidget::canView());
        $this->assertTrue(SubscriptionPlanStatsWidget::canView());
        $this->assertTrue(NotificationDeliveryStatsWidget::canView());
    }

    private function actingAsRole(string $role): SystemAdmin
    {
        $admin = SystemAdmin::factory()->role($role)->create([
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'system_admin');

        return $admin;
    }
}

