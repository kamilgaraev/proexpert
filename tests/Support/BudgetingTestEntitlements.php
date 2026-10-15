<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Modules\Core\AccessController;

final class BudgetingTestEntitlements
{
    public static function activate(AdminApiTestContext $context): void
    {
        Module::query()->firstOrCreate(['slug' => 'budgeting'], [
            'name' => 'Budgeting', 'version' => '1.0.0', 'type' => 'feature',
            'billing_model' => 'free', 'category' => 'finance',
            'permissions' => ['budgeting.limits.override'],
            'is_active' => true, 'is_system_module' => false,
        ]);
        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id,
            'responsible_user_id' => $context->user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'finance-contracts', 'status' => 'active',
            'access_source' => 'paid_package', 'price_paid' => 39900,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        app(AccessController::class)->clearAccessCache((int) $context->organization->id);
    }
}
