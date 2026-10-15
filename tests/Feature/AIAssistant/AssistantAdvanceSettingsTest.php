<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantAdvanceSettingsReader;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\DTOs\AdvanceAccountSettingDTO;
use App\Models\AdvanceAccountSetting;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAdvanceSettingsTest extends TestCase
{
    public function test_settings_match_native_defaults_and_current_org_and_recheck_permissions_and_source(): void
    {
        Queue::fake(); config(['cache.default' => 'array']);
        $f = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $org = (int) $f->organization->id; $reader = app(AssistantAdvanceSettingsReader::class);
        $access = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class);
        self::assertSame(['module' => true, 'settings' => true, 'finance' => true], [
            'module' => in_array('advance-accounting', $access->effectiveModuleSlugs($f->owner, $org), true),
            'settings' => $access->canCurrentPermission($f->owner, $org, 'advance_settings.view'),
            'finance' => $access->canCurrentPermission($f->owner, $org, 'finance.view'),
        ]);
        $defaults = $reader->read($f->owner, $org, ['scope' => 'organization']);
        self::assertFalse($defaults['data']['stored']);
        self::assertSame(AdvanceAccountSettingDTO::fromModelOrDefaults(null, $org)->toArray(), $defaults['data']['settings']);
        self::assertStringContainsString('значения по умолчанию', $defaults['server_formatted_answer']);
        self::assertSame(0, AdvanceAccountSetting::query()->where('organization_id', $org)->count());
        $foreign = AdvanceAccountSetting::withoutEvents(fn () => AdvanceAccountSetting::query()->create(['organization_id' => $f->foreignOrganization->id, 'max_single_issue_amount' => '987654.32']));
        $setting = AdvanceAccountSetting::withoutEvents(fn () => AdvanceAccountSetting::query()->create(['organization_id' => $org, 'max_single_issue_amount' => '15000.25',
            'report_submission_deadline_days' => 7, 'dual_authorization_threshold' => '10000.00', 'require_project_for_expense' => true,
            'notify_admin_on_overdue_report' => true, 'notify_admin_on_high_value_transaction' => false, 'high_value_notification_threshold' => null,
            'notify_user_on_transaction_approval' => true, 'notify_user_on_transaction_rejection' => true, 'send_report_reminder_days_before' => 2]));
        $read = $reader->read($f->owner, $org, ['scope' => 'organization']);
        self::assertTrue($read['data']['stored']);
        $native = AdvanceAccountSettingDTO::fromModel($setting->fresh())->toArray();
        foreach (['max_single_issue_amount', 'dual_authorization_threshold', 'high_value_notification_threshold'] as $key) { $native[$key] = $setting->getRawOriginal($key); }
        self::assertSame($native, $read['data']['settings']);
        self::assertSame($org, $read['data']['settings']['organization_id']);
        self::assertNull($read['source_refs'][0]['project_id']);
        self::assertStringContainsString('15000.25', $read['server_formatted_answer']);
        self::assertStringNotContainsString('987654', $read['server_formatted_answer']);
        self::assertStringContainsString('Валюта денежных порогов', $read['server_formatted_answer']);
        self::assertTrue($reader->matchesReference($f->owner, $org, $read['source_refs'][0]));
        $setting->updateQuietly(['high_value_notification_threshold' => '9999999999999.99']);
        $precise = $reader->read($f->owner, $org, ['scope' => 'organization']);
        self::assertSame('9999999999999.99', $precise['data']['settings']['high_value_notification_threshold']);
        self::assertStringContainsString('9999999999999.99', $precise['server_formatted_answer']);
        self::assertStringNotContainsString('10000000000000', $precise['server_formatted_answer']);
        $setting->updateQuietly(['report_submission_deadline_days' => 10]);
        self::assertFalse($reader->matchesReference($f->owner, $org, $read['source_refs'][0]));
        $member = $f->addMember(['ai-assistant' => ['ai_assistant.chat'], 'advance-accounting' => ['advance_settings.view']]);
        $selects = [];
        DB::listen(static function ($event) use (&$selects): void { if (str_contains($event->sql, 'advance_account_settings')) { $selects[] = $event->sql; } });
        self::assertFalse(app(AIPermissionChecker::class)->canExposeTool($member, 'get_organization_advance_settings'));
        try { $reader->read($member, $org, ['scope' => 'organization']); self::fail('Missing finance.view must deny settings'); } catch (AuthorizationException) { self::assertTrue(true); }
        self::assertSame([], $selects);
        $slug = UserRoleAssignment::query()->where('user_id', $member->id)->value('role_slug');
        $role = OrganizationCustomRole::query()->where('organization_id', $org)->where('slug', $slug)->firstOrFail();
        $role->updateQuietly(['system_permissions' => ['finance.view']]);
        self::assertTrue(app(AIPermissionChecker::class)->canExposeTool($member, 'get_organization_advance_settings'));
        $granted = $reader->read($member, $org, ['scope' => 'organization']);
        self::assertSame(10, $granted['data']['settings']['report_submission_deadline_days']);
        $role->updateQuietly(['system_permissions' => []]);
        self::assertFalse($reader->matchesReference($member, $org, $granted['source_refs'][0]));
        try { $reader->read($f->owner, (int) $foreign->organization_id, ['scope' => 'organization']); self::fail('Foreign organization must be denied'); } catch (AuthorizationException) { self::assertTrue(true); }
        try { $reader->read($f->owner, $org, ['scope' => 'organization', 'organization_id' => $foreign->organization_id]); self::fail('Caller organization override must be denied'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
    }
}
