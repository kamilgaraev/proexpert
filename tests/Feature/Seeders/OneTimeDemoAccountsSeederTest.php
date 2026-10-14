<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OneTimeDemoAccountsSeederTest extends TestCase
{
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
                    ->where('role_slug', 'organization_owner')
                    ->where('is_active', true)
                    ->exists()
            );
        }
    }

}
