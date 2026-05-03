<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class OneTimeDemoAccountsSeederTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('authorization_contexts');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');

        parent::tearDown();
    }

    public function test_it_creates_ten_verified_demo_accounts_with_separate_organizations_and_roles(): void
    {
        Artisan::call('db:seed', [
            '--class' => 'OneTimeDemoAccountsSeeder',
        ]);

        $users = User::query()
            ->where('email', 'like', 'demo.%@prohelper.test')
            ->with('organizations')
            ->get();

        $this->assertCount(10, $users);
        $this->assertCount(10, $users->pluck('current_organization_id')->unique());

        foreach ($users as $user) {
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue($user->is_active);
            $this->assertNotNull($user->current_organization_id);

            $organization = Organization::query()->findOrFail($user->current_organization_id);

            $this->assertTrue($organization->is_active);
            $this->assertTrue($organization->is_verified);
            $this->assertTrue($organization->onboarding_completed);
            $this->assertSame([$organization->primary_business_type], $organization->capabilities);
            $this->assertTrue(
                $organization->users()
                    ->whereKey($user->id)
                    ->wherePivot('is_owner', true)
                    ->wherePivot('is_active', true)
                    ->exists()
            );

            $context = AuthorizationContext::getOrganizationContext($organization->id);

            $this->assertTrue(
                UserRoleAssignment::query()
                    ->where('user_id', $user->id)
                    ->where('context_id', $context->id)
                    ->where('is_active', true)
                    ->exists()
            );
        }
    }

    private function createSchema(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable()->unique();
            $table->string('registration_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->default('RU');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_data')->nullable();
            $table->string('verification_status')->default('pending');
            $table->foreignId('parent_organization_id')->nullable();
            $table->string('organization_type')->default('single');
            $table->boolean('is_holding')->default(false);
            $table->json('multi_org_settings')->nullable();
            $table->integer('hierarchy_level')->default(0);
            $table->string('hierarchy_path')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('primary_business_type', 100)->nullable();
            $table->json('specializations')->nullable();
            $table->json('certifications')->nullable();
            $table->unsignedTinyInteger('profile_completeness')->default(0);
            $table->boolean('onboarding_completed')->default(false);
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->string('avatar_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('current_organization_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->boolean('has_completed_onboarding')->default(false);
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
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });

        Schema::create('authorization_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->json('metadata')->nullable();
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
    }
}
