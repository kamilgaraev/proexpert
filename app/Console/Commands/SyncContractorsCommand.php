<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Organization;
use App\Models\Contractor;
use App\Modules\Core\AccessController;

class SyncContractorsCommand extends Command
{
    protected $signature = 'contractors:sync 
                            {--organization-id= : ID конкретной дочерней организации}
                            {--holding-id= : ID холдинговой организации для синхронизации всех дочерних}
                            {--force : Пропустить проверку модуля multi-organization}';

    protected $description = 'Синхронизировать контрагентов от родительской организации к дочерним';

    public function handle(AccessController $accessController): int
    {
        $organizationId = $this->option('organization-id');
        $holdingId = $this->option('holding-id');
        $force = $this->option('force');

        if (!$organizationId && !$holdingId) {
            $this->error('Укажите --organization-id или --holding-id');
            return 1;
        }

        if ($organizationId) {
            return $this->syncForOrganization($organizationId, $accessController, $force);
        }

        if ($holdingId) {
            return $this->syncForHolding($holdingId, $accessController, $force);
        }

        return 1;
    }

    protected function syncForOrganization(int $orgId, AccessController $accessController, bool $force): int
    {
        $org = Organization::find($orgId);

        if (!$org) {
            $this->error("Организация #{$orgId} не найдена");
            return 1;
        }

        if (!$org->parent_organization_id) {
            $this->error("Организация #{$orgId} не является дочерней");
            return 1;
        }

        if (!$force && !$accessController->hasModuleAccess($orgId, 'multi-organization')) {
            $this->warn("Модуль multi-organization не активен для организации #{$orgId}");
            return 1;
        }

        $this->info("Синхронизация контрагентов для организации: {$org->name} (#{$orgId})");
        $this->info("Родительская организация: #{$org->parent_organization_id}");

        $result = Contractor::syncFromParentOrganization($orgId, $org->parent_organization_id);

        $this->newLine();
        $this->info("✓ Результаты синхронизации:");
        $this->line("  • Всего контрагентов в родительской организации: {$result['total']}");
        $this->line("  • Создано новых: {$result['created']}");
        $this->line("  • Обновлено существующих: {$result['synced']}");

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->warn("⚠ Ошибки при синхронизации:");
            foreach ($result['errors'] as $error) {
                $this->error("  Contractor #{$error['contractor_id']}: {$error['error']}");
            }
        }

        return 0;
    }

    protected function syncForHolding(int $holdingId, AccessController $accessController, bool $force): int
    {
        $holding = Organization::find($holdingId);

        if (!$holding) {
            $this->error("Организация #{$holdingId} не найдена");
            return 1;
        }

        if (!$holding->is_holding) {
            $this->error("Организация #{$holdingId} не является холдинговой");
            return 1;
        }

        if (!$force && !$accessController->hasModuleAccess($holdingId, 'multi-organization')) {
            $this->warn("Модуль multi-organization не активен для холдинга #{$holdingId}");
            return 1;
        }

        $this->info("Синхронизация контрагентов для холдинга: {$holding->name} (#{$holdingId})");

        $childOrgs = Organization::where('parent_organization_id', $holdingId)->get();

        if ($childOrgs->isEmpty()) {
            $this->warn("У холдинга нет дочерних организаций");
            return 0;
        }

        $this->info("Найдено дочерних организаций: {$childOrgs->count()}");
        $this->newLine();

        $totalCreated = 0;
        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($childOrgs as $child) {
            $this->line("→ {$child->name} (#{$child->id})");

            $result = Contractor::syncFromParentOrganization($child->id, $holdingId);

            $this->line("  • Создано: {$result['created']}, Обновлено: {$result['synced']}, Ошибок: " . count($result['errors']));

            $totalCreated += $result['created'];
            $totalSynced += $result['synced'];
            $totalErrors += count($result['errors']);
        }

        $this->newLine();
        $this->info("✓ Итого:");
        $this->line("  • Всего создано: {$totalCreated}");
        $this->line("  • Всего обновлено: {$totalSynced}");
        $this->line("  • Всего ошибок: {$totalErrors}");

        return 0;
    }
}
