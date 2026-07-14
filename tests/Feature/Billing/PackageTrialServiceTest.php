<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationPackageTrialUsage;
use App\Services\Billing\PackageTrialService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class PackageTrialServiceTest extends TestCase
{
    private Organization $organization;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = $this->createOrganization('Пробная организация');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_starts_exactly_72_hour_trial_without_billing_period_or_auto_renew(): void
    {
        $now = CarbonImmutable::parse('2026-07-14 10:15:30', 'UTC');
        CarbonImmutable::setTestNow($now);
        Cache::put("org_effective_active_modules_v2_{$this->organization->id}", ['stale']);

        $subscription = app(PackageTrialService::class)->start(
            $this->organization->id,
            'estimates-norms',
        );

        $account = OrganizationCommercialAccount::query()->sole();
        $usage = OrganizationPackageTrialUsage::query()->sole();

        $this->assertSame('free', $account->status->value);
        $this->assertSame('packages', $account->offer_type->value);
        $this->assertSame((int) config('commercial_offers.quote_version'), $account->quote_version);
        $this->assertFalse($account->auto_renew_enabled);
        $this->assertNull($account->billing_anchor_at);
        $this->assertNull($account->current_period_start_at);
        $this->assertNull($account->current_period_end_at);

        $this->assertSame('trialing', $subscription->status->value);
        $this->assertSame('trial', $subscription->access_source->value);
        $this->assertSame('0.00', $subscription->price_paid);
        $this->assertTrue($subscription->trial_started_at->equalTo($now));
        $this->assertTrue($subscription->trial_ends_at->equalTo($now->addHours(72)));
        $this->assertNull($subscription->current_period_start_at);
        $this->assertNull($subscription->current_period_end_at);
        $this->assertNull($subscription->cancel_at);

        $this->assertTrue($usage->started_at->equalTo($now));
        $this->assertTrue($usage->ends_at->equalTo($now->addHours(72)));
        $this->assertSame(72, (int) config('commercial_offers.trial_hours'));
        $this->assertFalse(Cache::has("org_effective_active_modules_v2_{$this->organization->id}"));
    }

    public function test_rejects_repeat_trial_and_preserves_original_history(): void
    {
        $now = CarbonImmutable::parse('2026-07-14 10:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);
        app(PackageTrialService::class)->start($this->organization->id, 'machinery');

        $usage = OrganizationPackageTrialUsage::query()->sole();
        $subscription = OrganizationPackageSubscription::query()->sole();

        CarbonImmutable::setTestNow($now->addDay());

        try {
            app(PackageTrialService::class)->start($this->organization->id, 'machinery');
            $this->fail('Повторный пробный доступ должен быть отклонен.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(409, $exception->getCode());
        }

        $this->assertSame(1, OrganizationPackageTrialUsage::query()->count());
        $this->assertSame(1, OrganizationPackageSubscription::query()->count());
        $this->assertTrue($usage->fresh()->started_at->equalTo($now));
        $this->assertTrue($usage->fresh()->ends_at->equalTo($now->addHours(72)));
        $this->assertTrue($subscription->fresh()->trial_started_at->equalTo($now));
        $this->assertTrue($subscription->fresh()->trial_ends_at->equalTo($now->addHours(72)));
    }

    public function test_rejects_trial_after_any_existing_package_subscription(): void
    {
        $account = $this->createAccount($this->organization);
        OrganizationPackageSubscription::create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'machinery',
            'status' => 'canceled',
            'access_source' => 'paid_package',
            'price_paid' => 7900,
            'canceled_at' => now()->subDay(),
        ]);

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);

        app(PackageTrialService::class)->start($this->organization->id, 'machinery');
    }

    public function test_allows_different_packages_and_same_package_for_another_organization(): void
    {
        $other = $this->createOrganization('Другая организация');

        app(PackageTrialService::class)->start($this->organization->id, 'machinery');
        app(PackageTrialService::class)->start($this->organization->id, 'estimates-norms');
        app(PackageTrialService::class)->start($other->id, 'machinery');

        $this->assertSame(3, OrganizationPackageTrialUsage::query()->count());
        $this->assertSame(3, OrganizationPackageSubscription::query()->count());
    }

    public function test_rejects_unknown_package_without_creating_commercial_state(): void
    {
        try {
            app(PackageTrialService::class)->start($this->organization->id, 'unknown-package');
            $this->fail('Неизвестный пакет должен быть отклонен.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(404, $exception->getCode());
        }

        $this->assertSame(0, OrganizationCommercialAccount::query()->count());
        $this->assertSame(0, OrganizationPackageTrialUsage::query()->count());
        $this->assertSame(0, OrganizationPackageSubscription::query()->count());
    }

    public function test_trial_usage_model_refuses_update_and_delete(): void
    {
        app(PackageTrialService::class)->start($this->organization->id, 'machinery');
        $usage = OrganizationPackageTrialUsage::query()->sole();
        $originalEndsAt = $usage->ends_at->format('Y-m-d H:i:s');

        try {
            $usage->update(['ends_at' => now()->addWeek()]);
            $this->fail('История пробного доступа не должна обновляться.');
        } catch (LogicException) {
            $this->assertDatabaseHas('organization_package_trial_usages', [
                'id' => $usage->id,
                'ends_at' => $originalEndsAt,
            ]);
        }

        $this->expectException(LogicException::class);
        $usage->delete();
    }

    private function createOrganization(string $name): Organization
    {
        return Organization::withoutEvents(static fn (): Organization => Organization::create([
            'name' => $name,
            'is_active' => true,
            'is_verified' => true,
        ]));
    }

    private function createAccount(Organization $organization): OrganizationCommercialAccount
    {
        return OrganizationCommercialAccount::create([
            'organization_id' => $organization->id,
            'status' => 'free',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'auto_renew_enabled' => false,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('organization_package_trial_usages');
        Schema::dropIfExists('organization_package_subscriptions');
        Schema::dropIfExists('organization_commercial_accounts');
        Schema::dropIfExists('organizations');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->string('status');
            $table->string('offer_type');
            $table->unsignedInteger('quote_version');
            $table->timestamp('billing_anchor_at')->nullable();
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->boolean('auto_renew_enabled');
            $table->timestamps();
        });

        Schema::create('organization_package_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id');
            $table->string('package_slug', 100);
            $table->string('status');
            $table->string('access_source');
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->timestamp('current_period_start_at')->nullable();
            $table->timestamp('current_period_end_at')->nullable();
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });

        Schema::create('organization_package_trial_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('package_slug', 100);
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->unique(['organization_id', 'package_slug']);
        });
    }
}
