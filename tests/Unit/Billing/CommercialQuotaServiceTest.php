<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\OrganizationResourceAllocation;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\CommercialQuotaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommercialQuotaServiceTest extends TestCase
{
    private Organization $organization;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = Organization::withoutEvents(fn (): Organization => Organization::query()->create([
            'name' => 'Quota organization',
            'is_active' => true,
            'is_verified' => true,
            'storage_used_mb' => 1536,
        ]));
    }

    public function test_free_limits_include_usage_and_remaining_amounts(): void
    {
        $this->user('one@example.test');
        $this->user('two@example.test');
        Project::withoutEvents(fn (): Project => Project::query()->create([
            'name' => 'Project A',
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]));

        $summary = $this->quota()->getQuotaSummary($this->organization);

        $users = $this->limit($summary, 'users');
        $projects = $this->limit($summary, 'projects');
        $storage = $this->limit($summary, 'storage_gb');

        $this->assertSame('free', $summary['account_status']);
        $this->assertSame(2, $users['used']);
        $this->assertSame(3, $users['limit']);
        $this->assertSame(1, $users['remaining']);
        $this->assertSame('warning', $users['status']);
        $this->assertSame(1, $projects['used']);
        $this->assertSame(2, $projects['limit']);
        $this->assertSame(1.5, $storage['used']);
        $this->assertSame(2, $storage['limit']);
        $this->assertSame('hard', $storage['enforcement']);
    }

    public function test_package_and_paid_addon_limits_are_added_to_free_base(): void
    {
        $account = $this->account('active');
        $this->package($account, 'projects-processes');
        OrganizationResourceAllocation::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'resource_slug' => 'extra_users',
            'limit_key' => 'users',
            'quantity' => 5,
            'source' => 'paid_addon',
            'status' => 'active',
        ]);

        $summary = $this->quota()->getQuotaSummary($this->organization);

        $this->assertSame(18, $this->limit($summary, 'users')['limit']);
        $this->assertSame(12, $this->limit($summary, 'projects')['limit']);
        $this->assertSame(22, $this->limit($summary, 'storage_gb')['limit']);
        $this->assertSame(550, $this->limit($summary, 'ai_requests_month')['limit']);
        $this->assertSame(10, $this->limit($summary, 'users')['sources']['packages']);
        $this->assertSame(5, $this->limit($summary, 'users')['sources']['paid_addons']);
        $this->assertSame(10, $this->limit($summary, 'projects')['sources']['packages']);
    }

    public function test_summary_returns_every_configured_limit_and_resource_addon_with_user_facing_payload(): void
    {
        $account = $this->account('active');
        $this->package($account, 'projects-processes');
        OrganizationResourceAllocation::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'resource_slug' => 'storage_gb',
            'limit_key' => 'storage_gb',
            'quantity' => 10,
            'source' => 'paid_addon',
            'status' => 'active',
        ]);

        $summary = $this->quota()->getQuotaSummary($this->organization);

        $this->assertSame(array_keys(config('commercial_limits.limits')), array_column($summary['limits'], 'key'));
        foreach ($summary['limits'] as $limit) {
            $this->assertNotSame('', trim($limit['name']));
            $this->assertNotSame($limit['key'], $limit['name']);
            $this->assertArrayHasKey('free_base', $limit['sources']);
            $this->assertArrayHasKey('packages', $limit['sources']);
            $this->assertArrayHasKey('paid_addons', $limit['sources']);
        }

        $expectedResourceSlugs = collect(config('commercial_limits.resources'))
            ->sortBy('sort_order')
            ->keys()
            ->values()
            ->all();

        $this->assertSame($expectedResourceSlugs, array_column($summary['resource_addons'], 'slug'));
        foreach ($summary['resource_addons'] as $resource) {
            $this->assertNotSame('', trim($resource['name']));
            $this->assertNotSame($resource['slug'], $resource['name']);
            $this->assertArrayHasKey('price_minor', $resource['pricing']);
            $this->assertArrayHasKey('amount', $resource['pricing']);
        }

        $storageLimit = $this->limit($summary, 'storage_gb');
        $this->assertSame(32, $storageLimit['limit']);
        $this->assertSame(20, $storageLimit['sources']['packages']);
        $this->assertSame(10, $storageLimit['sources']['paid_addons']);

        $storageResource = $this->resourceAddon($summary, 'storage_gb');
        $this->assertSame('Дополнительное хранилище', $storageResource['name']);
        $this->assertSame(10, $storageResource['current_quantity']);
        $this->assertSame(10, $storageResource['step']);
        $this->assertSame(2000, $storageResource['pricing']['price_minor']);
        $this->assertSame('20.00', $storageResource['pricing']['amount']);
        $this->assertTrue($storageResource['available']);

        $documentPages = $this->resourceAddon($summary, 'extra_document_pages');
        $this->assertSame('estimates-norms', $documentPages['requires_package']);
        $this->assertFalse($documentPages['available']);
    }

    public function test_corporate_override_can_set_unlimited_limit(): void
    {
        $account = $this->account('corporate', 'corporate');
        OrganizationResourceAllocation::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'resource_slug' => 'corporate-users-unlimited',
            'limit_key' => 'users',
            'quantity' => null,
            'source' => 'corporate_override',
            'status' => 'active',
        ]);

        $users = $this->limit($this->quota()->getQuotaSummary($this->organization), 'users');

        $this->assertNull($users['limit']);
        $this->assertNull($users['remaining']);
        $this->assertSame('ok', $users['status']);
        $this->assertNull($users['sources']['corporate_override']);
    }

    public function test_assert_can_use_blocks_hard_limit_when_delta_exceeds_remaining(): void
    {
        $this->user('one@example.test');
        $this->user('two@example.test');
        $this->user('three@example.test');

        $this->expectException(CommercialQuotaExceededException::class);

        $this->quota()->assertCanUse($this->organization, 'users');
    }

    public function test_quote_marks_package_required_resource_unavailable_without_package(): void
    {
        $quote = $this->quota()->calculateResourceAddonQuote($this->organization, [
            ['slug' => 'extra_document_pages', 'quantity' => 500],
        ]);

        $this->assertTrue($quote['requires_manager']);
        $this->assertSame('package_required', $quote['items'][0]['status']);
        $this->assertSame(0, $quote['amount_minor']);
    }

    private function quota(): CommercialQuotaService
    {
        return app(CommercialQuotaService::class);
    }

    private function limit(array $summary, string $key): array
    {
        $limits = array_values(array_filter(
            $summary['limits'],
            static fn (array $limit): bool => $limit['key'] === $key,
        ));

        $this->assertCount(1, $limits);

        return $limits[0];
    }

    private function resourceAddon(array $summary, string $slug): array
    {
        $resources = array_values(array_filter(
            $summary['resource_addons'],
            static fn (array $resource): bool => $resource['slug'] === $slug,
        ));

        $this->assertCount(1, $resources);

        return $resources[0];
    }

    private function user(string $email): User
    {
        $user = User::withoutEvents(fn (): User => User::query()->create([
            'name' => 'Quota User',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]));
        $user->organizations()->attach($this->organization->id, [
            'is_owner' => false,
            'is_active' => true,
        ]);

        return $user;
    }

    private function account(string $status, string $offerType = 'packages'): OrganizationCommercialAccount
    {
        return OrganizationCommercialAccount::query()->create([
            'organization_id' => $this->organization->id,
            'status' => $status,
            'offer_type' => $offerType,
            'quote_version' => 1,
            'current_period_start_at' => now()->subDays(10),
            'current_period_end_at' => now()->addDays(20),
            'auto_renew_enabled' => true,
        ]);
    }

    private function package(OrganizationCommercialAccount $account, string $slug): OrganizationPackageSubscription
    {
        return OrganizationPackageSubscription::query()->create([
            'organization_id' => $this->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => $slug,
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 9900,
            'current_period_start_at' => now()->subDays(10),
            'current_period_end_at' => now()->addDays(20),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'organization_resource_allocations',
            'organization_package_subscriptions',
            'organization_commercial_accounts',
            'projects',
            'organization_user',
            'users',
            'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->decimal('storage_used_mb', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('organization_id');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_commercial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique();
            $table->foreignId('responsible_user_id')->nullable();
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
            $table->timestamps();
        });
        Schema::create('organization_resource_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('commercial_account_id')->nullable();
            $table->string('resource_slug', 100);
            $table->string('limit_key', 100);
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('source', 50);
            $table->string('status', 50);
            $table->timestamp('period_start_at')->nullable();
            $table->timestamp('period_end_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
