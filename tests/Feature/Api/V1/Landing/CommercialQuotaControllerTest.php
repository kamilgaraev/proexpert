<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CommercialQuotaControllerTest extends TestCase
{
    private Organization $organization;

    private User $owner;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->organization = Organization::withoutEvents(fn (): Organization => Organization::query()->create([
            'name' => 'Quota API organization',
            'is_active' => true,
            'is_verified' => true,
            'storage_used_mb' => 512,
        ]));
        $this->owner = $this->user('quota-owner@example.test');
        $this->attachRole($this->owner, 'organization_owner');
    }

    public function test_owner_can_load_limits_summary(): void
    {
        $response = $this->authenticatedAs($this->owner)
            ->getJson('/api/v1/landing/billing/limits');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_status', 'free')
            ->assertJsonPath('data.limits.0.key', 'users')
            ->assertJsonPath('data.resource_addons.0.slug', 'extra_users');
    }

    public function test_owner_can_quote_resource_addons(): void
    {
        $response = $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/commercial/resource-addons/quote',
            ['resources' => [['slug' => 'extra_users', 'quantity' => 5]]],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.currency', 'RUB')
            ->assertJsonPath('data.requires_manager', false)
            ->assertJsonPath('data.items.0.slug', 'extra_users')
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.amount_minor', 150000);
    }

    public function test_quote_rejects_invalid_quantity(): void
    {
        $this->authenticatedAs($this->owner)->postJson(
            '/api/v1/landing/billing/resource-addons/quote',
            ['resources' => [['slug' => 'extra_users', 'quantity' => -1]]],
        )->assertUnprocessable();
    }

    private function authenticatedAs(User $user): self
    {
        $token = JWTAuth::claims(['organization_id' => $this->organization->id])->fromUser($user);

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function user(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Quota Owner',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'current_organization_id' => $this->organization->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->organizations()->attach($this->organization->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        return $user;
    }

    private function attachRole(User $user, string $role): void
    {
        UserRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role_slug' => $role,
            'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->organization->id)->id,
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'organization_resource_allocations',
            'organization_package_subscriptions',
            'organization_commercial_accounts',
            'role_conditions',
            'user_role_assignments',
            'organization_custom_roles',
            'authorization_contexts',
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
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('current_organization_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->string('project_access_mode')->nullable();
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
        Schema::create('authorization_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('organization_custom_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->json('system_permissions');
            $table->json('module_permissions');
            $table->json('interface_access');
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role_slug', 100)->index();
            $table->string('role_type')->default('system');
            $table->unsignedBigInteger('context_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['user_id', 'role_slug', 'context_id'], 'unique_user_role_context');
        });
        Schema::create('role_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->string('condition_type')->index();
            $table->json('condition_data');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
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
