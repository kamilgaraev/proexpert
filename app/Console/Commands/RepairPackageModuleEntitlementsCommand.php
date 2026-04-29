<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SubscriptionModuleSyncService;
use Illuminate\Console\Command;

class RepairPackageModuleEntitlementsCommand extends Command
{
    protected $signature = 'entitlements:repair-package-modules {organizationId?}';

    protected $description = 'Repair materialized module activations from effective package entitlements';

    public function __construct(
        private readonly SubscriptionModuleSyncService $syncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizationId = $this->argument('organizationId');
        $result = $this->syncService->repairPackageModuleActivations(
            $organizationId !== null ? (int) $organizationId : null
        );

        $this->info('Package module entitlements repair completed.');
        $this->line("Organizations: {$result['organizations_count']}");
        $this->line("Created: {$result['created_count']}");
        $this->line("Restored: {$result['restored_count']}");
        $this->line("Skipped: {$result['skipped_count']}");
        $this->line("Missing modules: {$result['missing_modules_count']}");

        return self::SUCCESS;
    }
}
