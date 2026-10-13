<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAssignableRolesReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationCreditBalanceReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationUsersReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnAdminPermissionsReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationPackagesReader;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationModulesReader;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditWallet;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use App\Services\User\AdminUserRolePolicy;
use App\Services\Landing\PackageService;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAdminGovernanceCoverageTest extends TestCase
{
    private function fixture(): AssistantRealAuthorizationFixture
    {
        Queue::fake(); config(['cache.default' => 'array']);

        return Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
    }

    public function test_user_counts_match_native_scope_and_do_not_hydrate_contacts_or_staff(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id;
        $disabled = User::withoutEvents(fn () => User::factory()->create(['is_active' => false, 'email' => 'private_disabled@example.test']));
        $disabled->organizations()->attach($org, ['is_active' => false]);
        $reader = app(AssistantOrganizationUsersReader::class);
        $result = $reader->read($f->owner, $org, ['scope' => 'organization']);
        self::assertSame(['total' => 4, 'active' => 3, 'inactive' => 1, 'unspecified' => 0], array_intersect_key($result['data'], array_flip(['total', 'active', 'inactive', 'unspecified'])));
        self::assertStringNotContainsString('private_disabled', json_encode($result));
        self::assertStringNotContainsString('email', json_encode($result));
        $disabled->updateQuietly(['is_active' => true]);
        self::assertFalse($reader->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertSame(4, $reader->read($f->owner, $org, ['scope' => 'organization'])['data']['active']);
        try { $reader->read($f->member, $org, ['scope' => 'organization']); self::fail('Missing user view permission'); }
        catch (AuthorizationException) { self::assertTrue(true); }
        self::assertFalse($reader->matchesReference($f->foreignOwner, $org, $result['source_refs'][0]));
    }

    public function test_assignable_roles_and_own_permissions_use_fresh_native_policy_after_revocation(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $roles = app(AssistantAssignableRolesReader::class);
        $result = $roles->read($f->owner, $org, $args);
        self::assertSame(app(AdminUserRolePolicy::class)->optionsFor($f->owner, $org), $result['data']['roles']);
        self::assertTrue($result['data']['can_assign_organization_admin']);
        self::assertTrue($result['data']['can_edit_users']);
        $permissions = app(AssistantOwnAdminPermissionsReader::class);
        $owned = $permissions->read($f->owner, $org, $args);
        self::assertTrue($owned['data']['permissions']['users']['view']);
        self::assertTrue($owned['data']['permissions']['contracts']['edit']);
        $limited = $permissions->read($f->member, $org, $args);
        self::assertFalse($limited['data']['permissions']['users']['view']);
        self::assertFalse($limited['data']['permissions']['invoices']['view_all']);
        $f->ownerAssignment->updateQuietly(['is_active' => false]);
        self::assertFalse($roles->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertFalse($permissions->matchesReference($f->owner, $org, $owned['source_refs'][0]));
        self::assertSame([], app(AdminUserRolePolicy::class)->optionsFor($f->owner, $org));
    }

    public function test_credit_balance_uses_wallet_reservations_expiry_and_holds_without_mutation(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $reader = app(AssistantOrganizationCreditBalanceReader::class);
        $zero = $reader->read($f->owner, $org, $args);
        self::assertSame(0, $zero['data']['available_minor']);
        self::assertSame(0, AICreditWallet::query()->where('organization_id', $org)->count());
        $wallet = AICreditWallet::query()->create(['organization_id' => $org, 'balance_minor' => 2000, 'reserved_minor' => 300]);
        $subscription = AICreditLot::query()->create(['organization_id' => $org, 'source' => 'subscription', 'original_minor' => 1200, 'remaining_minor' => 900, 'expires_at' => now()->addDay()]);
        AICreditLot::query()->create(['organization_id' => $org, 'source' => 'purchase', 'original_minor' => 400, 'remaining_minor' => 400]);
        AICreditLot::query()->create(['organization_id' => $org, 'source' => 'conversion', 'original_minor' => 200, 'remaining_minor' => 200, 'metadata' => [AICreditLot::LEGACY_REFUND_HOLD => true]]);
        $expired = AICreditLot::query()->create(['organization_id' => $org, 'source' => 'subscription', 'original_minor' => 200, 'remaining_minor' => 200, 'expires_at' => now()->subDay()]);
        AICreditWallet::query()->create(['organization_id' => $f->foreignOrganization->id, 'balance_minor' => 987654, 'reserved_minor' => 0]);
        $result = $reader->read($f->member, $org, $args);
        foreach (['total_minor' => 1800, 'available_minor' => 1300, 'reserved_minor' => 300, 'reconciliation_held_minor' => 200, 'included_minor' => 900, 'purchased_minor' => 600, 'unit_minor' => 100] as $key => $expected) {
            self::assertSame($expected, $result['data'][$key], $key);
        }
        self::assertSame(2000, $wallet->fresh()->balance_minor);
        self::assertSame(200, $expired->fresh()->remaining_minor);
        self::assertSame(0, DB::table('ai_credit_ledger_entries')->where('organization_id', $org)->count());
        self::assertStringNotContainsString('987654', json_encode($result));
        self::assertStringContainsString('13', $result['server_formatted_answer']);
        $subscription->updateQuietly(['expires_at' => now()->subHour()]);
        self::assertFalse($reader->matchesReference($f->member, $org, $result['source_refs'][0]));
        $current = $reader->read($f->member, $org, $args);
        self::assertSame(900, $current['data']['total_minor']);
        self::assertSame(400, $current['data']['available_minor']);
        $f->member->organizations()->updateExistingPivot($org, ['is_active' => false]);
        self::assertFalse($reader->matchesReference($f->member, $org, $current['source_refs'][0]));
    }

    public function test_packages_and_modules_follow_native_effective_access_and_expiry(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $packages = app(AssistantOrganizationPackagesReader::class);
        $result = $packages->read($f->owner, $org, $args);
        $native = array_values(array_filter(app(PackageService::class)->getAllPackages($org), static fn (array $row): bool => $row['is_active']));
        self::assertSame(array_column($native, 'slug'), array_column($result['data']['packages'], 'slug'));
        self::assertSame(count($native), $result['data']['total']);
        self::assertStringNotContainsString('price', json_encode($result));
        $modules = app(AssistantOrganizationModulesReader::class);
        $effective = $modules->read($f->member, $org, $args);
        $expectedSlugs = app(OrganizationEntitlementService::class)->getEffectiveModuleSlugs($org);
        $actualSlugs = array_column($effective['data']['modules'], 'slug');
        sort($expectedSlugs); sort($actualSlugs);
        self::assertSame($expectedSlugs, $actualSlugs);
        self::assertGreaterThan(0, $effective['data']['total']);
        try { $packages->read($f->member, $org, $args); self::fail('Missing billing view permission'); }
        catch (AuthorizationException) { self::assertTrue(true); }
        $f->subscription->updateQuietly(['current_period_end_at' => now()->subDay()]);
        self::assertFalse($packages->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertNotContains('working-entry', array_column(array_filter(app(PackageService::class)->getAllPackages($org), static fn (array $row): bool => $row['is_active']), 'slug'));
        $f->owner->organizations()->updateExistingPivot($org, ['is_active' => false]);
        self::assertFalse($modules->matchesReference($f->owner, $org, $effective['source_refs'][0]));
    }
}
