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

    public function test_contacts_and_notification_preferences_use_native_personal_scope_and_fresh_authorization(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $f->organization->updateQuietly(['phone' => '+70000000000', 'email' => 'office@example.test', 'address' => 'Адрес профиля', 'postal_code' => '420000']);
        $contacts = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationContactsReader::class);
        $contact = $contacts->read($f->owner, $org, $args);
        self::assertSame('office@example.test', $contact['data']['email']);
        self::assertSame('Адрес профиля', $contact['data']['address']);
        self::assertStringContainsString('профиля', $contact['server_formatted_answer']);
        $f->organization->updateQuietly(['phone' => '+71111111111']);
        self::assertFalse($contacts->matchesReference($f->owner, $org, $contact['source_refs'][0]));
        try { $contacts->read($f->member, $org, $args); self::fail('Contact permission missing'); }
        catch (AuthorizationException) { self::assertTrue(true); }
        self::assertFalse($contacts->matchesReference($f->foreignOwner, $org, $contact['source_refs'][0]));
        config(['notifications.channels' => ['email' => ['enabled' => true], 'in_app' => ['enabled' => true]],
            'notifications.types' => ['custom' => ['name' => 'Личные события', 'description' => 'Тест', 'mandatory' => false, 'user_customizable' => true, 'default_channels' => ['email']],
                'fixed' => ['name' => 'Обязательные события', 'description' => 'Тест', 'mandatory' => true, 'user_customizable' => true, 'default_channels' => ['in_app']]]]);
        $preferences = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantNotificationPreferencesReader::class);
        $native = app(\App\BusinessModules\Features\Notifications\Services\LandingNotificationPreferencesService::class);
        $beforeCount = \App\BusinessModules\Features\Notifications\Models\NotificationPreference::query()->count();
        $result = $preferences->read($f->member, $org, $args);
        self::assertSame($native->read($f->member, $org)['items'][0]['enabled_channels'], $result['data']['items'][0]['enabled_channels']);
        self::assertFalse($result['data']['items'][1]['user_customizable']);
        self::assertSame($beforeCount, \App\BusinessModules\Features\Notifications\Models\NotificationPreference::query()->count());
        $native->update($f->owner, $org, 'custom', ['in_app']);
        self::assertTrue($preferences->matchesReference($f->member, $org, $result['source_refs'][0]));
        $native->update($f->member, $org, 'custom', ['in_app']);
        self::assertFalse($preferences->matchesReference($f->member, $org, $result['source_refs'][0]));
        $current = $preferences->read($f->member, $org, $args);
        self::assertSame(['in_app'], $current['data']['items'][0]['enabled_channels']);
        config(['notifications.channels.email.enabled' => false]);
        self::assertFalse($preferences->matchesReference($f->member, $org, $current['source_refs'][0]));
        $verifiedContact = $contacts->read($f->owner, $org, $args);
        $f->owner->forceFill(['email_verified_at' => null])->saveQuietly();
        self::assertFalse($contacts->matchesReference($f->owner, $org, $verifiedContact['source_refs'][0]));
        self::assertFalse(app(AssistantOwnAdminPermissionsReader::class)->read($f->owner, $org, $args)['data']['permissions']['organization']['edit']);
        $f->member->organizations()->updateExistingPivot($org, ['is_active' => false]);
        self::assertFalse($preferences->matchesReference($f->member, $org, $current['source_refs'][0]));
    }

    public function test_business_profile_verification_and_own_roles_are_current_native_reads_without_private_details(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization'];
        $f->organization->forceFill(['capabilities' => ['general_contracting', 'design'], 'primary_business_type' => 'general_contracting',
            'specializations' => ['building_construction', 'plumbing_works', 'Промышленное строительство'], 'certifications' => [['name' => 'private_certificate_marker']], 'profile_completeness' => 80,
            'onboarding_completed' => true, 'tax_number' => 'invalid_private_tax_value', 'address' => null,
            'verification_data' => ['inn_verification' => ['success' => false], 'address_verification' => ['success' => false], 'errors' => ['private_external_error_marker']]])->saveQuietly();
        $profile = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationProfileReader::class);
        $result = $profile->read($f->owner, $org, $args);
        $native = app(\App\Services\Organization\OrganizationProfileService::class)->getProfile($f->organization);
        self::assertSame($native->getProfileCompleteness(), $result['data']['profile_completeness']);
        self::assertSame($native->getCapabilities(), $result['data']['capabilities']);
        self::assertSame(count($native->getCertifications()), $result['data']['certifications_count']);
        self::assertStringContainsString('Промышленное и гражданское строительство', $result['server_formatted_answer']);
        self::assertStringContainsString('Сантехнические работы', $result['server_formatted_answer']);
        self::assertStringContainsString('первичная настройка завершена: да', $result['server_formatted_answer']);
        self::assertStringNotContainsString('building_construction', $result['server_formatted_answer']);
        self::assertStringNotContainsString('private_certificate_marker', json_encode($result));
        self::assertTrue($profile->matchesReference($f->owner, $org, $result['source_refs'][0]));
        $f->organization->forceFill(['profile_completeness' => 90])->saveQuietly();
        self::assertFalse($profile->matchesReference($f->owner, $org, $result['source_refs'][0]));
        $verificationService = new \App\Services\OrganizationVerificationService($this->createMock(\App\Services\DaDataService::class), $this->createMock(\App\Services\Logging\LoggingService::class));
        $this->app->instance(\App\Services\OrganizationVerificationService::class, $verificationService);
        $verification = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOrganizationVerificationReader::class);
        $verified = $verification->read($f->member, $org, $args);
        $recommendations = $verificationService->getVerificationRecommendations($f->organization->fresh());
        self::assertSame($recommendations['status'], $verified['data']['status']);
        self::assertSame($recommendations['current_score'], $verified['data']['score']);
        self::assertSame(1, $verified['data']['verification_issue_count']);
        self::assertStringNotContainsString('invalid_private_tax_value', json_encode($verified));
        self::assertStringNotContainsString('private_external_error_marker', json_encode($verified));
        self::assertStringNotContainsString('current_value', json_encode($verified));
        self::assertTrue($verification->matchesReference($f->member, $org, $verified['source_refs'][0]));
        $beforeContexts = \App\Domain\Authorization\Models\AuthorizationContext::query()->count();
        $roles = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnRolesReader::class);
        $owned = $roles->read($f->owner, $org, $args);
        self::assertContains('organization_owner', array_column($owned['data']['roles'], 'slug'));
        self::assertFalse($roles->matchesReference($f->foreignOwner, $org, $owned['source_refs'][0]));
        self::assertSame($beforeContexts, \App\Domain\Authorization\Models\AuthorizationContext::query()->count());
        self::assertStringNotContainsString('assigned_by', json_encode($owned));
        $f->ownerAssignment->updateQuietly(['expires_at' => now()->subSecond()]);
        self::assertFalse($roles->matchesReference($f->owner, $org, $owned['source_refs'][0]));
        self::assertSame('invalid_private_tax_value', $f->organization->fresh()->tax_number);
        self::assertSame(0, DB::table('report_dispatch_intents')->count());
    }

    public function test_personal_report_workspace_and_subscriptions_follow_native_scope_and_remain_read_only(): void
    {
        $f = $this->fixture(); $org = (int) $f->organization->id; $args = ['scope' => 'organization']; $code = 'budget_plan_fact';
        self::assertInstanceOf(\App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSavedViewController::class,
            app(\App\BusinessModules\Core\Reporting\Http\Admin\Controllers\ReportSavedViewController::class));
        $workspace = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportWorkspaceReader::class);
        $empty = $workspace->read($f->owner, $org, $args);
        self::assertSame([], $empty['data']['favourites']);
        self::assertSame(0, DB::table('report_workspace_preferences')->count());
        $this->travel(2)->seconds();
        self::assertTrue($workspace->matchesReference($f->owner, $org, $empty['source_refs'][0]));
        $store = app(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportWorkspacePreferencesStore::class);
        $store->updateLocked($org, (int) $f->owner->id, fn ($p) => new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences([$code], [$code], $p->display, now()->toDateTimeImmutable()));
        $store->updateLocked($org, (int) $f->administrator->id, fn ($p) => new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences([], [], $p->display, now()->toDateTimeImmutable()));
        self::assertFalse($workspace->matchesReference($f->owner, $org, $empty['source_refs'][0]));
        $saved = $workspace->read($f->owner, $org, $args);
        self::assertSame([$code], array_column($saved['data']['favourites'], 'code'));
        self::assertSame([$code], array_column($saved['data']['recent'], 'code'));
        self::assertFalse($workspace->matchesReference($f->foreignOwner, $org, $saved['source_refs'][0]));
        $subscriptionCode = 'handover_readiness';
        $definition = app(\App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry::class)->published($subscriptionCode)->payload();
        $records = [];
        foreach ([[$f->owner, $org], [$f->administrator, $org], [$f->foreignOwner, (int) $f->foreignOrganization->id]] as [$actor, $organization]) {
            $view = \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewRecord::query()->create([
                'id' => (string) Str::ulid(), 'organization_id' => $organization, 'owner_id' => $actor->id, 'report_code' => $subscriptionCode,
                'contract_version' => $definition->contractVersion, 'name' => 'Personal view', 'filters_json' => [],
                'sort_json' => ['field' => $definition->sorts[0]['id'], 'direction' => 'asc'], 'columns_json' => [$definition->columns[0]['id']]]);
            $bytes = '{"private_execution_marker":true}';
            $records[] = \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSubscriptionRecord::query()->create([
                'id' => (string) Str::ulid(), 'organization_id' => $organization, 'owner_id' => $actor->id, 'saved_view_id' => $view->id,
                'report_code' => $subscriptionCode, 'contract_version' => $definition->contractVersion, 'definition_sha256' => $definition->definitionHash->value,
                'frequency' => 'weekly', 'weekday' => 3, 'day_of_month' => null, 'local_time' => '10:00', 'timezone' => 'UTC',
                'period_policy_json' => ['mode' => 'previous_day'], 'format' => $definition->formats[0], 'status' => 'active', 'next_run_at' => now()->addDay(),
                'execution_input_bytes' => $bytes, 'execution_input_sha256' => hash('sha256', $bytes)]);
        }
        $shared = \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSavedViewRecord::query()
            ->where('organization_id', $org)->where('owner_id', $f->administrator->id)->firstOrFail()->replicate();
        $shared->fill(['id' => (string) Str::ulid(), 'name' => 'Общее устаревшее представление', 'visibility' => 'organization', 'contract_version' => 'old']);
        $shared->saveQuietly();
        $workspaceViews = $workspace->read($f->owner, $org, $args);
        self::assertSame(2, count($workspaceViews['data']['saved_views']));
        self::assertSame(['active', 'needs_migration'], array_column($workspaceViews['data']['saved_views'], 'status'));
        self::assertTrue($workspaceViews['data']['saved_views_complete']);
        self::assertStringContainsString('это разные списки', $workspaceViews['server_formatted_answer']);
        self::assertStringContainsString('Общее устаревшее представление', $workspaceViews['server_formatted_answer']);
        self::assertSame('active', $shared->fresh()->status);
        self::assertTrue($workspace->matchesReference($f->owner, $org, $workspaceViews['source_refs'][0]));
        $shared->updateQuietly(['visibility' => 'private']);
        self::assertFalse($workspace->matchesReference($f->owner, $org, $workspaceViews['source_refs'][0]));
        self::assertCount(1, $workspace->read($f->owner, $org, $args)['data']['saved_views']);
        $unavailable = $shared->replicate();
        $unavailable->fill(['id' => (string) Str::ulid(), 'name' => 'Hidden unavailable report', 'visibility' => 'organization',
            'report_code' => 'unknown_report_285', 'filters_json' => ['large_private_setting' => str_repeat('x', 1048577)]]);
        $unavailable->saveQuietly();
        $viewQueries = [];
        DB::listen(static function ($query) use (&$viewQueries): void {
            if (str_contains($query->sql, 'report_saved_views')) { $viewQueries[] = $query->sql; }
        });
        $limited = $workspace->read($f->owner, $org, $args);
        self::assertSame([$code], array_column($limited['data']['favourites'], 'code'));
        self::assertCount(1, $limited['data']['saved_views']);
        self::assertTrue($limited['data']['saved_views_unavailable']);
        self::assertStringNotContainsString('Hidden unavailable report', $limited['server_formatted_answer']);
        self::assertStringNotContainsString('filters_json', implode(' ', $viewQueries));
        self::assertStringNotContainsString('select *', implode(' ', $viewQueries));
        $subscriptions = app(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantReportSubscriptionsReader::class);
        $result = $subscriptions->read($f->owner, $org, $args);
        self::assertTrue($result['data']['complete']);
        self::assertSame([(string) $records[0]->id], array_column($result['data']['items'], 'id'));
        self::assertSame('active', $result['data']['items'][0]['status']);
        self::assertStringContainsString('среда', $result['server_formatted_answer']);
        self::assertStringNotContainsString('private_execution_marker', json_encode($result));
        self::assertStringNotContainsString('next_cursor', json_encode($result));
        self::assertTrue($subscriptions->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertFalse($subscriptions->matchesReference($f->foreignOwner, $org, $result['source_refs'][0]));
        $records[0]->updateQuietly(['status' => 'paused']);
        self::assertFalse($subscriptions->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertSame('paused', $subscriptions->read($f->owner, $org, $args)['data']['items'][0]['status']);
        try { $subscriptions->read($f->member, $org, $args); self::fail('Report manage permission missing'); }
        catch (AuthorizationException) { self::assertTrue(true); }
        $f->ownerAssignment->updateQuietly(['expires_at' => now()->subSecond()]);
        self::assertFalse($workspace->matchesReference($f->owner, $org, $saved['source_refs'][0]));
        self::assertFalse($subscriptions->matchesReference($f->owner, $org, $result['source_refs'][0]));
        self::assertSame(3, DB::table('report_subscriptions')->count());
        self::assertSame(0, DB::table('report_dispatch_intents')->count());
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
        self::assertTrue($owned['data']['permissions']['estimates']['export']);
        self::assertTrue($owned['data']['permissions']['warehouse']['manage_stock']);
        self::assertTrue($owned['data']['permissions']['reports']['download']);
        self::assertTrue($owned['data']['permissions']['organization']['edit']);
        self::assertTrue($owned['data']['permissions']['notifications']['manage_preferences']);
        self::assertStringContainsString('конкретный отчёт проверяются отдельно', $owned['server_formatted_answer']);
        $limited = $permissions->read($f->member, $org, $args);
        self::assertFalse($limited['data']['permissions']['users']['view']);
        self::assertFalse($limited['data']['permissions']['invoices']['view_all']);
        self::assertFalse($limited['data']['permissions']['estimates']['export']);
        self::assertFalse($limited['data']['permissions']['warehouse']['manage_stock']);
        self::assertFalse($limited['data']['permissions']['reports']['download']);
        self::assertTrue($limited['data']['permissions']['notifications']['manage_preferences']);
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
