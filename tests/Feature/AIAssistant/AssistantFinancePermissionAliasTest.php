<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantFinancePermissionAliasTest extends TestCase
{
    public function test_real_owner_finance_grant_requires_current_active_payments_and_assignment(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => (int) $fixture->organization->id];
        $payments = Module::query()->where('slug', 'payments')->firstOrFail();

        self::assertTrue($payments->is_system_module);
        self::assertFalse($payments->can_deactivate);
        self::assertTrue($authorization->can($fixture->owner, 'finance.view', $context));
        self::assertTrue($authorization->canCurrent($fixture->owner, 'finance.view', $context));
        self::assertFalse($authorization->canCurrent($fixture->member, 'finance.view', $context));
        self::assertFalse($authorization->canCurrent($fixture->foreignOwner, 'finance.view', $context));

        $payments->update(['is_active' => false]);
        self::assertFalse($authorization->canCurrent($fixture->owner, 'finance.view', $context));
        $payments->update(['is_active' => true]);
        self::assertTrue($authorization->canCurrent($fixture->owner, 'finance.view', $context));

        $fixture->ownerAssignment->update(['is_active' => false]);
        self::assertFalse($authorization->canCurrent($fixture->owner, 'finance.view', $context));
    }

    public function test_payments_wildcard_cannot_grant_or_restore_legacy_finance_permissions(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => (int) $fixture->organization->id];
        $paymentsOnly = $fixture->addMember(['payments' => ['*']]);

        self::assertFalse($authorization->canCurrent($paymentsOnly, 'finance.view', $context));
        self::assertFalse($authorization->canCurrent($paymentsOnly, 'finance.view_project_budget', $context));
        self::assertTrue($authorization->canCurrent($paymentsOnly, 'payments.invoice.view', $context));

        $fixture->memberRole->update(['module_permissions' => ['finance' => ['finance.view'], 'payments' => ['*']]]);
        self::assertTrue($authorization->can($fixture->member, 'finance.view', $context));
        self::assertTrue($authorization->canCurrent($fixture->member, 'finance.view', $context));
        $fixture->memberRole->update(['module_permissions' => ['payments' => ['*']]]);
        self::assertFalse($authorization->canCurrent($fixture->member, 'finance.view', $context));
        self::assertFalse($authorization->canCurrent($fixture->member, 'finance.view_project_budget', $context));
        self::assertTrue($authorization->canCurrent($fixture->member, 'payments.invoice.view', $context));
    }

    public function test_canonical_explicit_finance_grant_is_separate_from_payments_wildcard_and_revocable(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create();
        $authorization = app(AuthorizationService::class);
        $context = ['organization_id' => (int) $fixture->organization->id];
        $fixture->memberRole->update(['module_permissions' => ['payments' => ['*', 'finance.view']]]);

        self::assertTrue($authorization->can($fixture->member, 'finance.view', $context));
        self::assertTrue($authorization->canCurrent($fixture->member, 'finance.view', $context));
        self::assertFalse($authorization->canCurrent($fixture->member, 'finance.view_project_budget', $context));
        self::assertTrue($authorization->canCurrent($fixture->member, 'payments.invoice.view', $context));

        $fixture->memberRole->update(['module_permissions' => ['payments' => ['*']]]);
        self::assertFalse($authorization->canCurrent($fixture->member, 'finance.view', $context));
        self::assertFalse($authorization->canCurrent($fixture->member, 'finance.view_project_budget', $context));
        self::assertTrue($authorization->canCurrent($fixture->member, 'payments.invoice.view', $context));
    }
}
