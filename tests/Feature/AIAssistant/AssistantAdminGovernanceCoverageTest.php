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
use App\BusinessModules\Features\AIAssistant\Services\AssistantCurrentRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\Services\Credits\AICreditService;
use Illuminate\Support\Str;
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

    #[\PHPUnit\Framework\Attributes\DataProvider('snapshotTimezones')]
    public function test_completed_credit_snapshot_remains_visible_after_charge_but_is_not_replayed_as_a_new_balance(string $timezone): void
    {
        $previousConfig = config('app.timezone'); $previousTimezone = date_default_timezone_get();
        try {
            config(['app.timezone' => $timezone]); date_default_timezone_set($timezone);
            $this->assertCompletedCreditSnapshot();
        } finally { config(['app.timezone' => $previousConfig]); date_default_timezone_set($previousTimezone); }
    }

    public static function snapshotTimezones(): array
    {
        return ['UTC' => ['UTC'], 'Moscow' => ['Europe/Moscow']];
    }

    private function assertCompletedCreditSnapshot(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $actor = $f->owner;
        config(['ai-assistant-credits.enforce' => true]);
        $credits = app(AICreditService::class);
        $credits->grant($f->organization, 10000, 'purchase', null, 'snapshot-test-'.$org);
        $conversations = app(ConversationManager::class);
        $conversation = $conversations->createConversation($org, $actor, 'Баланс');
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Сколько единиц МОСТ осталось для помощника?', 'profile' => 'short',
            'allow_actions' => false, 'conversation_id' => $conversation->id, 'context' => []];
        $payload['quote_id'] = $credits->quote($f->organization, $actor, $payload)['quote_id'];
        $lifecycle = app(AssistantRequestLifecycle::class);
        $request = $lifecycle->start($f->organization, $actor, $conversation->id, $payload, 'admin')['request'];
        $scope = app(AssistantCurrentRequest::class); $scope->replace($request);
        try {
            $reader = app(AssistantOrganizationCreditBalanceReader::class);
            $result = $reader->read($actor, $org, ['scope' => 'organization']); $ref = $result['source_refs'][0];
            self::assertSame($request->request_id, $ref['origin_request_id']);
            self::assertStringContainsString('до его окончательного списания', $result['server_formatted_answer']);
            $expectedTime = \Carbon\CarbonImmutable::parse($ref['fetched_at'])->utc()->toIso8601String();
            self::assertStringContainsString($expectedTime.' (UTC)', $result['server_formatted_answer']);
            self::assertTrue($reader->matchesReference($actor, $org, $ref));
            $call = $lifecycle->beforeProviderCall($request, $actor, 100, 100);
            $lifecycle->recordProviderUsage($request, ['input_tokens' => 100, 'output_tokens' => 100, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'], $call);
            $message = $conversations->addMessage($conversation, 'assistant', $result['server_formatted_answer'], 0, 'gpt-6-luna', [
                'request_id' => $request->request_id, 'actor_user_id' => (int) $actor->id, 'request_state' => 'pending',
                'validation_status' => 'partial', 'source_refs' => [$ref]]);
            $response = $lifecycle->complete($request, $actor, ['message' => ['id' => $message->id, 'content' => $message->content, 'metadata' => $message->metadata]]);
            self::assertGreaterThan(0, $response['credit_usage']['charged_minor']);
            self::assertFalse($reader->matchesReference($actor, $org, $ref));
            $scope->replace(null);
            self::assertTrue($reader->matchesReference($actor, $org, $ref));
            self::assertSame('completed', $lifecycle->status($request->request_id, $actor, $org, 'admin')['status']);
            self::assertCount(1, $conversations->getHistory($conversation, 10, $actor));
            self::assertNull($reader->verifiedAnswer($result, $actor, $org));
            $forged = $ref; $forged['source_version'] = str_repeat('0', 64);
            self::assertFalse($reader->matchesReference($actor, $org, $forged));
            $forged = $ref; $forged['origin_request_id'] = (string) Str::uuid();
            self::assertFalse($reader->matchesReference($actor, $org, $forged));
            self::assertFalse($reader->matchesReference($f->member, $org, $ref));
            self::assertFalse($reader->matchesReference($f->foreignOwner, $org, $ref));
            $scope->replace(new AssistantRequest(['request_id' => (string) Str::uuid(), 'user_id' => (int) $actor->id, 'organization_id' => $org]));
            self::assertFalse($reader->matchesReference($actor, $org, $ref));
            $current = $reader->read($actor, $org, ['scope' => 'organization']);
            self::assertNotSame($ref['origin_request_id'], $current['source_refs'][0]['origin_request_id']);
            self::assertTrue($reader->matchesReference($actor, $org, $current['source_refs'][0]));
            $scope->replace(null);
            $legacy = $ref; unset($legacy['origin_request_id']);
            $stored = $request->fresh()->response;
            $stored['message']['metadata']['source_refs'] = [$legacy];
            $request->updateQuietly(['response' => $stored]);
            self::assertTrue($reader->matchesReference($actor, $org, $legacy));
            self::assertSame('completed', $lifecycle->status($request->request_id, $actor, $org, 'admin')['status']);
            $scope->replace(new AssistantRequest(['request_id' => (string) Str::uuid(), 'user_id' => (int) $actor->id, 'organization_id' => $org]));
            self::assertFalse($reader->matchesReference($actor, $org, $legacy));
            $scope->replace(null);
            $actor->organizations()->updateExistingPivot($org, ['is_active' => false]);
            self::assertFalse($reader->matchesReference($actor, $org, $legacy));
        } finally { $scope->replace(null); }
    }
}
