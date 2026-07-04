<?php

declare(strict_types=1);

namespace Tests\Feature\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\Organization;
use App\Models\User;
use App\Repositories\Landing\EloquentOrganizationDashboardRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentOrganizationDashboardRepositoryTest extends TestCase
{
    public function test_team_summary_counts_unique_active_organization_users_not_role_assignments(): void
    {
        $organization = Organization::factory()->create();
        $context = AuthorizationContext::getOrganizationContext((int) $organization->id);

        $firstUser = User::factory()->create(['current_organization_id' => $organization->id]);
        $secondUser = User::factory()->create(['current_organization_id' => $organization->id]);
        $inactiveMember = User::factory()->create(['current_organization_id' => $organization->id]);
        $foreignUser = User::factory()->create();

        $organization->users()->attach($firstUser->id, ['is_owner' => true, 'is_active' => true]);
        $organization->users()->attach($secondUser->id, ['is_owner' => false, 'is_active' => true]);
        $organization->users()->attach($inactiveMember->id, ['is_owner' => false, 'is_active' => false]);

        $now = now();

        DB::table('user_role_assignments')->insert([
            [
                'user_id' => $firstUser->id,
                'role_slug' => 'organization_owner',
                'role_type' => 'system',
                'context_id' => $context->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $firstUser->id,
                'role_slug' => 'foreman',
                'role_type' => 'system',
                'context_id' => $context->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $secondUser->id,
                'role_slug' => 'foreman',
                'role_type' => 'system',
                'context_id' => $context->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $secondUser->id,
                'role_slug' => 'accountant',
                'role_type' => 'system',
                'context_id' => $context->id,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $inactiveMember->id,
                'role_slug' => 'foreman',
                'role_type' => 'system',
                'context_id' => $context->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $foreignUser->id,
                'role_slug' => 'foreman',
                'role_type' => 'system',
                'context_id' => $context->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $summary = (new EloquentOrganizationDashboardRepository())->getTeamSummary((int) $organization->id);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['by_roles']['organization_owner']);
        $this->assertSame(2, $summary['by_roles']['foreman']);
        $this->assertArrayNotHasKey('accountant', $summary['by_roles']);
    }
}
