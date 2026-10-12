<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Addons\FileManagement\FileManagementModule;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\DesignManagement\DesignManagementModule;
use App\BusinessModules\Features\ExecutiveDocumentation\ExecutiveDocumentationModule;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Services\ReportTemplates\ReportTemplatesModule;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Modules\Core\AccessController;

final class DesignManagementTestEntitlements
{
    public static function activate(AdminApiTestContext $context): void
    {
        $modules = [
            [new DesignManagementModule(), 'ModuleList/features/design-management.json'],
            [new ExecutiveDocumentationModule(), 'ModuleList/features/executive-documentation.json'],
            [new ProjectManagementModule(), 'ModuleList/features/project-management.json'],
            [new ContractManagementModule(), 'ModuleList/features/contract-management.json'],
            [new FileManagementModule(), 'ModuleList/addons/file-management.json'],
            [new ReportTemplatesModule(), 'ModuleList/services/report-templates.json'],
        ];
        foreach ($modules as [$module, $configFile]) {
            $manifest = $module->getManifest();
            Module::query()->updateOrCreate(['slug' => $module->getSlug()], [
                'name' => $module->getName(), 'version' => $module->getVersion(), 'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value, 'category' => $manifest['category'] ?? 'construction',
                'description' => $module->getDescription(), 'features' => $module->getFeatures(), 'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(), 'conflicts' => $module->getConflicts(), 'limits' => $module->getLimits(),
                'class_name' => $module::class, 'config_file' => $configFile, 'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true, 'is_system_module' => false,
            ]);
        }
        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id, 'responsible_user_id' => $context->user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => $now, 'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $context->organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry', 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 39900,
            'current_period_start_at' => $now, 'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        app(AccessController::class)->clearAccessCache((int) $context->organization->id);
    }
}
