<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Monitoring\ApplicationErrorResource;
use App\Filament\Widgets\PlatformRiskStatsWidget;
use App\Models\ApplicationError;
use App\Models\SystemAdmin;
use App\Services\Filament\SystemAdminDashboardService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class MonitoringAndSupportCockpitTest extends TestCase
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

    public function test_monitoring_resource_requires_monitoring_permission(): void
    {
        $auditor = SystemAdmin::factory()->role('security_auditor')->create(['is_active' => true]);
        $contentManager = SystemAdmin::factory()->role('content_manager')->create(['is_active' => true]);

        $this->actingAs($auditor, 'system_admin');
        $this->assertTrue($auditor->hasSystemPermission('system_admin.monitoring.view'));
        $this->assertTrue(ApplicationErrorResource::canViewAny());
        $this->assertFalse(ApplicationErrorResource::canCreate());
        $this->assertFalse(ApplicationErrorResource::canDeleteAny());

        $this->actingAs($contentManager, 'system_admin');
        $this->assertFalse($contentManager->hasSystemPermission('system_admin.monitoring.view'));
        $this->assertFalse(ApplicationErrorResource::canViewAny());
    }

    public function test_monitoring_resource_is_read_only_with_explicit_status_workflow(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/Monitoring/ApplicationErrorResource.php'));

        $this->assertStringContainsString("self::statusAction('mark_resolved'", $source);
        $this->assertStringContainsString("self::statusAction('mark_ignored'", $source);
        $this->assertStringContainsString("self::statusAction('mark_unresolved'", $source);
        $this->assertStringContainsString('SystemAdminAuditService::class', $source);
        $this->assertStringNotContainsString('DeleteAction', $source);
        $this->assertStringNotContainsString('stack_trace', $source);
        $this->assertStringNotContainsString("TextColumn::make('context", $source);
    }

    public function test_dashboard_counts_recent_application_errors_without_secret_fields(): void
    {
        ApplicationError::query()->create([
            'error_hash' => 'hash-recent-critical',
            'error_group' => 'Runtime critical',
            'exception_class' => 'RuntimeException',
            'message' => 'Critical payment sync failed',
            'file' => base_path('app/Services/BillingService.php'),
            'line' => 42,
            'stack_trace' => 'hidden stack trace',
            'context' => ['token' => 'must-not-render'],
            'occurrences' => 3,
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'status' => 'unresolved',
            'severity' => 'critical',
        ]);

        ApplicationError::query()->create([
            'error_hash' => 'hash-old-warning',
            'error_group' => 'Old warning',
            'exception_class' => 'LogicException',
            'message' => 'Old warning',
            'file' => base_path('app/Services/OldService.php'),
            'line' => 7,
            'stack_trace' => 'old stack trace',
            'occurrences' => 1,
            'first_seen_at' => now()->subDays(10),
            'last_seen_at' => now()->subDays(8),
            'status' => 'unresolved',
            'severity' => 'warning',
        ]);

        $overview = app(SystemAdminDashboardService::class)->overview();
        $widgetSource = (string) file_get_contents(app_path('Filament/Widgets/PlatformRiskStatsWidget.php'));

        $this->assertSame(1, $overview['monitoring']['application_errors_24_hours']);
        $this->assertSame(1, $overview['monitoring']['critical_application_errors']);
        $this->assertStringContainsString("widgets.platform_risk.application_errors", $widgetSource);
        $this->assertStringNotContainsString('stack_trace', $widgetSource);
        $this->assertStringNotContainsString('context', $widgetSource);
    }
}
