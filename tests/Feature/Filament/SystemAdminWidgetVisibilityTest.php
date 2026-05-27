<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Filament\Widgets\PlatformGrowthStatsWidget;
use App\Filament\Widgets\PlatformHealthStatsWidget;
use App\Filament\Widgets\PlatformRiskStatsWidget;
use App\Models\Activity\ActivityEvent;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\ContactForm;
use App\Models\LandingAdmin;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionPlan;
use App\Models\SystemAdmin;
use App\Models\User;
use App\Services\Filament\SystemAdminDashboardService;
use App\Services\Security\SystemAdminRoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        CarbonImmutable::setTestNow();
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_dashboard_service_aggregates_bounded_operational_metrics(): void
    {
        $this->assertTrue(class_exists(SystemAdminDashboardService::class), 'System admin dashboard service is missing.');

        $now = CarbonImmutable::parse('2026-05-27 12:00:00');
        CarbonImmutable::setTestNow($now);
        $baseline = app(SystemAdminDashboardService::class)->overview($now);

        $plan = SubscriptionPlan::query()->create([
            'name' => 'Dashboard Business',
            'slug' => 'dashboard-business-' . Str::uuid()->toString(),
            'price' => 10000,
            'currency' => 'RUB',
            'billing_cycle' => 'monthly',
            'trial_days' => 14,
            'features' => [],
            'is_active' => true,
            'display_order' => 1,
        ]);
        $activeOrganization = Organization::factory()->create(['is_active' => true]);
        $trialOrganization = Organization::factory()->create(['is_active' => true]);
        $overdueOrganization = Organization::factory()->create(['is_active' => true]);
        Organization::factory()->create(['is_active' => false]);

        OrganizationSubscription::query()->create([
            'organization_id' => $activeOrganization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $now->subMonth(),
            'ends_at' => $now->addMonth(),
            'next_billing_at' => $now->addWeek(),
        ]);
        OrganizationSubscription::query()->create([
            'organization_id' => $trialOrganization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'trial',
            'trial_ends_at' => $now->addDays(5),
            'starts_at' => $now->subDays(2),
            'ends_at' => $now->addDays(5),
        ]);
        OrganizationSubscription::query()->create([
            'organization_id' => $overdueOrganization->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => $now->subMonths(2),
            'ends_at' => $now->subDay(),
        ]);

        PaymentTransaction::unguarded(fn (): PaymentTransaction => PaymentTransaction::query()->create([
            'organization_id' => $activeOrganization->id,
            'amount' => 5000,
            'currency' => 'RUB',
            'payment_method' => 'card',
            'transaction_date' => $now->toDateString(),
            'status' => PaymentTransactionStatus::FAILED,
            'created_at' => $now->subDays(3),
            'updated_at' => $now->subDays(3),
        ]));
        PaymentTransaction::unguarded(fn (): PaymentTransaction => PaymentTransaction::query()->create([
            'organization_id' => $activeOrganization->id,
            'amount' => 5000,
            'currency' => 'RUB',
            'payment_method' => 'card',
            'transaction_date' => $now->subDays(45)->toDateString(),
            'status' => PaymentTransactionStatus::FAILED,
            'created_at' => $now->subDays(45),
            'updated_at' => $now->subDays(45),
        ]));

        User::factory()->create(['created_at' => $now->subDays(2), 'updated_at' => $now->subDays(2)]);
        User::factory()->create(['created_at' => $now->subDays(10), 'updated_at' => $now->subDays(10)]);
        User::factory()->create(['created_at' => $now->subDays(40), 'updated_at' => $now->subDays(40)]);

        $category = BlogCategory::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Dashboard Metrics',
            'slug' => 'dashboard-metrics-' . Str::uuid()->toString(),
            'is_active' => true,
        ]);
        $author = LandingAdmin::query()->create([
            'name' => 'Dashboard Editor',
            'email' => 'dashboard-editor-' . Str::uuid()->toString() . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        BlogArticle::query()->create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'title' => 'Draft article',
            'slug' => 'draft-article',
            'content' => 'Draft content',
            'status' => BlogArticleStatusEnum::DRAFT,
        ]);
        BlogArticle::query()->create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'title' => 'Published article',
            'slug' => 'published-article',
            'content' => 'Published content',
            'status' => BlogArticleStatusEnum::PUBLISHED,
            'published_at' => $now->subDay(),
        ]);
        BlogArticle::query()->create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'title' => 'Scheduled article',
            'slug' => 'scheduled-article',
            'content' => 'Scheduled content',
            'status' => BlogArticleStatusEnum::SCHEDULED,
            'scheduled_at' => $now->addDay(),
        ]);

        ContactForm::query()->create([
            'name' => 'Support Client',
            'email' => 'support-client@example.test',
            'subject' => 'Need help',
            'message' => 'Client needs support with billing.',
            'status' => ContactForm::STATUS_NEW,
            'priority' => ContactForm::PRIORITY_NORMAL,
            'channel' => ContactForm::CHANNEL_PUBLIC_FORM,
        ]);
        ContactForm::query()->create([
            'name' => 'Support Client 2',
            'email' => 'support-client-2@example.test',
            'subject' => 'Need help again',
            'message' => 'Client needs support with access.',
            'status' => ContactForm::STATUS_PROCESSING,
            'priority' => ContactForm::PRIORITY_URGENT,
            'channel' => ContactForm::CHANNEL_CUSTOMER_PORTAL,
        ]);
        ContactForm::query()->create([
            'name' => 'Solved Client',
            'email' => 'solved-client@example.test',
            'subject' => 'Solved',
            'message' => 'Already solved.',
            'status' => ContactForm::STATUS_COMPLETED,
            'priority' => ContactForm::PRIORITY_NORMAL,
            'channel' => ContactForm::CHANNEL_PUBLIC_FORM,
        ]);

        ActivityEvent::query()->create([
            'module' => 'system_admin',
            'event_type' => 'system_admin.support.escalated',
            'action' => 'updated',
            'severity' => 'critical',
            'title' => 'Critical support escalation',
            'occurred_at' => $now->subHours(2),
        ]);
        ActivityEvent::query()->create([
            'module' => 'system_admin',
            'event_type' => 'system_admin.users.blocked',
            'action' => 'updated',
            'severity' => 'warning',
            'title' => 'Old warning',
            'occurred_at' => $now->subDays(3),
        ]);

        $metrics = app(SystemAdminDashboardService::class)->overview($now);

        $this->assertSame($baseline['organizations']['active'] + 3, $metrics['organizations']['active']);
        $this->assertSame($baseline['organizations']['trial'] + 1, $metrics['organizations']['trial']);
        $this->assertSame($baseline['organizations']['paying'] + 1, $metrics['organizations']['paying']);
        $this->assertSame($baseline['subscriptions']['overdue'] + 1, $metrics['subscriptions']['overdue']);
        $this->assertSame($baseline['payments']['failed_30_days'] + 1, $metrics['payments']['failed_30_days']);
        $this->assertSame($baseline['users']['new_7_days'] + 1, $metrics['users']['new_7_days']);
        $this->assertSame($baseline['users']['new_30_days'] + 2, $metrics['users']['new_30_days']);
        $this->assertSame($baseline['blog']['draft'] + 1, $metrics['blog']['draft']);
        $this->assertSame($baseline['blog']['published'] + 1, $metrics['blog']['published']);
        $this->assertSame($baseline['blog']['scheduled'] + 1, $metrics['blog']['scheduled']);
        $this->assertSame($baseline['support']['pending'] + 1, $metrics['support']['pending']);
        $this->assertSame($baseline['support']['urgent'] + 1, $metrics['support']['urgent']);
        $this->assertSame($baseline['audit']['high_risk_24_hours'] + 1, $metrics['audit']['high_risk_24_hours']);
    }

    public function test_platform_widgets_are_visible_only_for_matching_system_admin_roles(): void
    {
        $this->assertTrue(class_exists(PlatformHealthStatsWidget::class), 'Platform health widget is missing.');
        $this->assertTrue(class_exists(PlatformGrowthStatsWidget::class), 'Platform growth widget is missing.');
        $this->assertTrue(class_exists(PlatformRiskStatsWidget::class), 'Platform risk widget is missing.');

        $this->actingAsRole('content_manager');

        $this->assertFalse(PlatformHealthStatsWidget::canView());
        $this->assertTrue(PlatformGrowthStatsWidget::canView());
        $this->assertFalse(PlatformRiskStatsWidget::canView());

        $this->actingAsRole('support_viewer');

        $this->assertFalse(PlatformHealthStatsWidget::canView());
        $this->assertFalse(PlatformGrowthStatsWidget::canView());
        $this->assertTrue(PlatformRiskStatsWidget::canView());

        $this->actingAsRole('qa_engineer');

        $this->assertTrue(PlatformHealthStatsWidget::canView());
        $this->assertTrue(PlatformGrowthStatsWidget::canView());
        $this->assertTrue(PlatformRiskStatsWidget::canView());

        Auth::guard('system_admin')->logout();
        $this->actingAs(User::factory()->create());

        $this->assertFalse(PlatformHealthStatsWidget::canView());
        $this->assertFalse(PlatformGrowthStatsWidget::canView());
        $this->assertFalse(PlatformRiskStatsWidget::canView());
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
