<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Organization;
use App\Models\Project;
use App\Enums\ProjectOrganizationRole;
use Illuminate\Support\Facades\DB;

class ValidateMultiOrgCommand extends Command
{
    protected $signature = 'multi-org:validate {--fix : Автоматически исправить найденные проблемы}';
    protected $description = 'Проверка целостности multi-organization структуры';

    protected int $issuesFound = 0;
    protected int $issuesFixed = 0;

    public function handle(): int
    {
        $fix = $this->option('fix');

        $this->info('=== Валидация структуры Multi-Organization ===');
        $this->newLine();

        $this->validateHoldingStructure($fix);
        $this->validateProjectParentAccess($fix);
        $this->validateContractorHierarchy($fix);
        $this->validateOrphanedRecords($fix);

        $this->newLine();
        $this->info('=== Итоги валидации ===');
        $this->line("Найдено проблем: {$this->issuesFound}");
        
        if ($fix && $this->issuesFixed > 0) {
            $this->info("Исправлено проблем: {$this->issuesFixed}");
        } elseif ($this->issuesFound > 0 && !$fix) {
            $this->warn('Запустите с флагом --fix для автоматического исправления');
        }

        return $this->issuesFound === 0 ? 0 : 1;
    }

    protected function validateHoldingStructure(bool $fix): void
    {
        $this->line('→ Проверка структуры холдингов...');

        $holdings = Organization::where('is_holding', true)->get();

        foreach ($holdings as $holding) {
            $childCount = Organization::where('parent_organization_id', $holding->id)->count();
            
            if ($childCount === 0) {
                $this->issuesFound++;
                $this->warn("  ⚠ Холдинг \"{$holding->name}\" (#{$holding->id}) не имеет дочерних организаций");
            }
        }

        $childWithoutParent = Organization::whereNotNull('parent_organization_id')
            ->whereDoesntHave('parentOrganization')
            ->get();

        foreach ($childWithoutParent as $child) {
            $this->issuesFound++;
            $this->error("  ✗ Организация \"{$child->name}\" (#{$child->id}) ссылается на несуществующего родителя #{$child->parent_organization_id}");

            if ($fix) {
                $child->parent_organization_id = null;
                $child->save();
                $this->issuesFixed++;
                $this->info("    ✓ Исправлено: удалена ссылка на несуществующего родителя");
            }
        }

        if ($this->issuesFound === 0) {
            $this->info('  ✓ Структура холдингов корректна');
        }
    }

    protected function validateProjectParentAccess(bool $fix): void
    {
        $this->line('→ Проверка доступа родительских организаций к проектам...');
        $initialIssues = $this->issuesFound;

        $childOrgs = Organization::whereNotNull('parent_organization_id')->get();

        foreach ($childOrgs as $childOrg) {
            $projects = Project::where('organization_id', $childOrg->id)->get();

            foreach ($projects as $project) {
                $parentHasAccess = DB::table('project_organization')
                    ->where('project_id', $project->id)
                    ->where('organization_id', $childOrg->parent_organization_id)
                    ->where('role', ProjectOrganizationRole::PARENT_ADMINISTRATOR->value)
                    ->exists();

                if (!$parentHasAccess) {
                    $this->issuesFound++;
                    $this->warn("  ⚠ Проект \"{$project->name}\" (#{$project->id}) не имеет доступа для родительской организации #{$childOrg->parent_organization_id}");

                    if ($fix) {
                        DB::table('project_organization')->insert([
                            'project_id' => $project->id,
                            'organization_id' => $childOrg->parent_organization_id,
                            'role' => ProjectOrganizationRole::PARENT_ADMINISTRATOR->value,
                            'role_new' => ProjectOrganizationRole::PARENT_ADMINISTRATOR->value,
                            'is_active' => true,
                            'invited_at' => now(),
                            'accepted_at' => now(),
                            'metadata' => json_encode(['auto_added' => true, 'source' => 'validation_fix'])
                        ]);
                        $this->issuesFixed++;
                        $this->info("    ✓ Исправлено: добавлен parent_administrator");
                    }
                }
            }
        }

        if ($this->issuesFound === $initialIssues) {
            $this->info('  ✓ Доступ родительских организаций корректен');
        }
    }

    protected function validateContractorHierarchy(bool $fix): void
    {
        $this->line('→ Проверка иерархии контрагентов...');
        $initialIssues = $this->issuesFound;

        $contractors = DB::table('contractors')
            ->whereNotNull('source_organization_id')
            ->get();

        foreach ($contractors as $contractor) {
            $sourceOrgExists = Organization::where('id', $contractor->source_organization_id)->exists();

            if (!$sourceOrgExists) {
                $this->issuesFound++;
                $this->error("  ✗ Контрагент \"{$contractor->name}\" (#{$contractor->id}) ссылается на несуществующую организацию #{$contractor->source_organization_id}");

                if ($fix) {
                    DB::table('contractors')
                        ->where('id', $contractor->id)
                        ->update(['source_organization_id' => null]);
                    $this->issuesFixed++;
                    $this->info("    ✓ Исправлено: удалена ссылка");
                }
            }
        }

        if ($this->issuesFound === $initialIssues) {
            $this->info('  ✓ Иерархия контрагентов корректна');
        }
    }

    protected function validateOrphanedRecords(bool $fix): void
    {
        $this->line('→ Проверка потерянных записей...');
        $initialIssues = $this->issuesFound;

        $orphanedProjects = Project::whereDoesntHave('organization')->count();
        if ($orphanedProjects > 0) {
            $this->issuesFound++;
            $this->error("  ✗ Найдено проектов без организации: {$orphanedProjects}");
        }

        $orphanedContracts = DB::table('contracts')
            ->leftJoin('organizations', 'contracts.organization_id', '=', 'organizations.id')
            ->whereNull('organizations.id')
            ->count();

        if ($orphanedContracts > 0) {
            $this->issuesFound++;
            $this->error("  ✗ Найдено контрактов без организации: {$orphanedContracts}");
        }

        if ($this->issuesFound === $initialIssues) {
            $this->info('  ✓ Потерянные записи не найдены');
        }
    }
}
