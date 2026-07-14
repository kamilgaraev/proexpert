<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Entitlements\OrganizationEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CommercialGraceEntitlementTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('organization_module_activations');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_commercial_accounts');
        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->boolean('auto_renew_enabled');
            $table->timestamp('grace_started_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug');
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 12, 2);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('source_order_id')->nullable();
            $table->timestamps();
        });
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->boolean('is_active');
            $table->boolean('can_deactivate');
            $table->boolean('is_system_module');
        });
        Schema::create('organization_module_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('module_id');
            $table->string('status');
            $table->boolean('is_bundled_with_plan');
            $table->timestamp('expires_at')->nullable();
        });
        DB::table('modules')->insert([
            'slug' => 'free-foundation', 'is_active' => true,
            'can_deactivate' => false, 'is_system_module' => true,
        ]);
    }

    public function test_real_entitlement_service_keeps_paid_modules_during_grace_only(): void
    {
        $due = CarbonImmutable::parse('2026-07-31 14:00', 'Europe/Moscow')->utc();
        $graceEnd = CarbonImmutable::parse('2026-08-07 00:00', 'Europe/Moscow')->utc();
        $targetEnd = $due->addDays(30);
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => 1, 'status' => 'active', 'offer_type' => 'packages',
            'quote_version' => 1, 'auto_renew_enabled' => true,
        ]);
        $row = OrganizationPackageSubscription::query()->create([
            'organization_id' => 1, 'commercial_account_id' => $account->id,
            'package_slug' => 'machinery', 'status' => 'active', 'access_source' => 'paid_package',
            'price_paid' => 7900, 'current_period_start_at' => $due->subDays(30), 'current_period_end_at' => $due,
        ]);
        $foreignAccount = OrganizationCommercialAccount::query()->create([
            'organization_id' => 2, 'status' => 'grace', 'offer_type' => 'packages',
            'quote_version' => 1, 'auto_renew_enabled' => true, 'grace_ends_at' => $graceEnd,
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => 1, 'commercial_account_id' => $foreignAccount->id,
            'package_slug' => 'planning-schedules', 'status' => 'grace', 'access_source' => 'paid_package',
            'price_paid' => 7900, 'current_period_end_at' => $due,
        ]);
        $service = app(OrganizationEntitlementService::class);

        CarbonImmutable::setTestNow($due->subHour());
        $this->assertArrayHasKey('machinery-operations', $service->getPackageModuleSources(1));

        $account->forceFill(['status' => 'grace', 'grace_started_at' => $due, 'grace_ends_at' => $graceEnd])->save();
        $row->forceFill(['status' => 'grace'])->save();
        CarbonImmutable::setTestNow($due->addHour());
        $sources = $service->getPackageModuleSources(1);
        $this->assertArrayHasKey('machinery-operations', $sources);
        $this->assertArrayNotHasKey('schedule-management', $sources);
        $this->assertTrue($row->fresh()->isActive());

        CarbonImmutable::setTestNow($graceEnd);
        $this->assertArrayNotHasKey('machinery-operations', $service->getPackageModuleSources(1));
        $this->assertFalse($row->fresh()->isActive());
        $foundation = $service->getEffectiveModuleSlugs(1);
        $this->assertNotEmpty($foundation);
        $this->assertNotContains('machinery-operations', $foundation);

        $account->forceFill(['status' => 'active', 'grace_started_at' => null, 'grace_ends_at' => null])->save();
        $row->forceFill(['status' => 'active', 'current_period_start_at' => $due, 'current_period_end_at' => $targetEnd])->save();
        CarbonImmutable::setTestNow($due->addDays(6));
        $this->assertArrayHasKey('machinery-operations', $service->getPackageModuleSources(1));
        $this->assertEquals($targetEnd, $row->fresh()->current_period_end_at);
    }
}
