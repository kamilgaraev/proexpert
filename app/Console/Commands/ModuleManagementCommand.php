<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrganizationModule;
use App\Models\OrganizationModuleActivation;
use App\Services\Landing\OrganizationModuleService;

class ModuleManagementCommand extends Command
{
    protected $signature = 'modules:manage 
                           {action : Action to perform (list, activate, deactivate, renew, check)}
                           {--org= : Organization ID}
                           {--module= : Module slug}
                           {--days=30 : Days for renewal}';

    protected $description = 'Manage organization modules';

    protected OrganizationModuleService $moduleService;

    public function __construct(OrganizationModuleService $moduleService)
    {
        parent::__construct();
        $this->moduleService = $moduleService;
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        match ($action) {
            'list' => $this->listModules(),
            'activate' => $this->activateModule(),
            'deactivate' => $this->deactivateModule(),
            'renew' => $this->renewModule(),
            'check' => $this->checkModule(),
            default => $this->error("Unknown action: {$action}")
        };

        return 0;
    }

    private function listModules(): void
    {
        $orgId = $this->option('org');

        if ($orgId) {
            $this->info("Modules for organization {$orgId}:");
            $modules = $this->moduleService->getOrganizationModulesWithStatus($orgId);
            
            foreach ($modules as $category => $categoryModules) {
                $this->line("\n<comment>{$category}:</comment>");
                foreach ($categoryModules as $moduleData) {
                    $module = $moduleData['module'];
                    $status = $moduleData['is_activated'] ? 'ACTIVE' : 'INACTIVE';
                    $price = $module->price > 0 ? "{$module->price} {$module->currency}" : 'FREE';
                    
                    $this->line("  - {$module->name} ({$module->slug}) - {$status} - {$price}");
                    
                    if ($moduleData['is_activated'] && $moduleData['expires_at']) {
                        $this->line("    Expires: {$moduleData['expires_at']}");
                    }
                }
            }
        } else {
            $this->info("All available modules:");
            $modules = $this->moduleService->getModulesByCategory();
            
            foreach ($modules as $category => $categoryModules) {
                $this->line("\n<comment>{$category}:</comment>");
                foreach ($categoryModules as $module) {
                    $price = $module->price > 0 ? "{$module->price} {$module->currency}" : 'FREE';
                    $this->line("  - {$module->name} ({$module->slug}) - {$price}");
                }
            }
        }
    }

    private function activateModule(): void
    {
        $orgId = $this->option('org');
        $moduleSlug = $this->option('module');

        if (!$orgId || !$moduleSlug) {
            $this->error('Organization ID and module slug are required');
            return;
        }

        $module = OrganizationModule::where('slug', $moduleSlug)->first();
        if (!$module) {
            $this->error("Module not found: {$moduleSlug}");
            return;
        }

        try {
            $activation = $this->moduleService->activateModule($orgId, $module->id);
            $this->info("Module {$moduleSlug} activated for organization {$orgId}");
        } catch (\Exception $e) {
            $this->error("Failed to activate module: {$e->getMessage()}");
        }
    }

    private function deactivateModule(): void
    {
        $orgId = $this->option('org');
        $moduleSlug = $this->option('module');

        if (!$orgId || !$moduleSlug) {
            $this->error('Organization ID and module slug are required');
            return;
        }

        $module = OrganizationModule::where('slug', $moduleSlug)->first();
        if (!$module) {
            $this->error("Module not found: {$moduleSlug}");
            return;
        }

        $result = $this->moduleService->deactivateModule($orgId, $module->id);
        
        if ($result) {
            $this->info("Module {$moduleSlug} deactivated for organization {$orgId}");
        } else {
            $this->error("Failed to deactivate module");
        }
    }

    private function renewModule(): void
    {
        $orgId = $this->option('org');
        $moduleSlug = $this->option('module');
        $days = (int) $this->option('days');

        if (!$orgId || !$moduleSlug) {
            $this->error('Organization ID and module slug are required');
            return;
        }

        $module = OrganizationModule::where('slug', $moduleSlug)->first();
        if (!$module) {
            $this->error("Module not found: {$moduleSlug}");
            return;
        }

        try {
            $activation = $this->moduleService->renewModule($orgId, $module->id, $days);
            $this->info("Module {$moduleSlug} renewed for {$days} days");
            $this->line("New expiration date: {$activation->expires_at}");
        } catch (\Exception $e) {
            $this->error("Failed to renew module: {$e->getMessage()}");
        }
    }

    private function checkModule(): void
    {
        $orgId = $this->option('org');
        $moduleSlug = $this->option('module');

        if (!$orgId || !$moduleSlug) {
            $this->error('Organization ID and module slug are required');
            return;
        }

        $hasAccess = $this->moduleService->hasModuleAccess($orgId, $moduleSlug);
        
        if ($hasAccess) {
            $this->info("Organization {$orgId} HAS access to module {$moduleSlug}");
        } else {
            $this->warn("Organization {$orgId} does NOT have access to module {$moduleSlug}");
        }
    }
} 