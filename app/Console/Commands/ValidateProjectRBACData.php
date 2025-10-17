<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Models\Organization;
use App\Services\Project\ProjectContextService;
use App\Services\Organization\OrganizationProfileService;
use App\Enums\ProjectOrganizationRole;
use Illuminate\Support\Facades\DB;

class ValidateProjectRBACData extends Command
{
    protected $signature = 'rbac:validate 
                          {--fix : Автоматически исправить найденные проблемы}
                          {--verbose : Показать детальную информацию}';

    protected $description = 'Валидация консистентности данных Project-Based RBAC';

    protected ProjectContextService $projectContextService;
    protected OrganizationProfileService $organizationProfileService;
    protected array $issues = [];
    protected array $fixed = [];

    public function __construct(
        ProjectContextService $projectContextService,
        OrganizationProfileService $organizationProfileService
    ) {
        parent::__construct();
        $this->projectContextService = $projectContextService;
        $this->organizationProfileService = $organizationProfileService;
    }

    public function handle(): int
    {
        $this->info('🔍 Начало валидации Project-Based RBAC данных...');
        $this->newLine();

        // 1. Проверка project owners
        $this->checkProjectOwners();

        // 2. Проверка ролей
        $this->checkRoles();

        // 3. Проверка capabilities vs roles
        $this->checkCapabilitiesVsRoles();

        // 4. Проверка orphaned записей
        $this->checkOrphanedRecords();

        // 5. Проверка дубликатов
        $this->checkDuplicates();

        // 6. Проверка is_active флагов
        $this->checkActiveFlags();

        $this->newLine();
        $this->displaySummary();

        return count($this->issues) === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function checkProjectOwners(): void
    {
        $this->info('📋 Проверка project owners...');

        $projects = Project::all();
        $missingOwners = [];

        foreach ($projects as $project) {
            $ownerInPivot = DB::table('project_organization')
                ->where('project_id', $project->id)
                ->where('organization_id', $project->organization_id)
                ->exists();

            if (!$ownerInPivot) {
                $missingOwners[] = $project;
                $this->issues[] = "Project #{$project->id} '{$project->name}' не имеет owner в project_organization";

                if ($this->option('fix')) {
                    DB::table('project_organization')->insert([
                        'project_id' => $project->id,
                        'organization_id' => $project->organization_id,
                        'role' => 'owner',
                        'role_new' => 'owner',
                        'is_active' => true,
                        'invited_at' => $project->created_at,
                        'accepted_at' => $project->created_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->fixed[] = "Добавлен owner для проекта #{$project->id}";
                    $this->line("  ✅ Исправлено: добавлен owner для проекта #{$project->id}");
                }
            }
        }

        if (count($missingOwners) === 0) {
            $this->line('  ✅ Все проекты имеют owners');
        } else {
            $this->warn("  ⚠️  Найдено проектов без owners: " . count($missingOwners));
        }
    }

    protected function checkRoles(): void
    {
        $this->info('📋 Проверка ролей в project_organization...');

        $invalidRoles = DB::table('project_organization')
            ->whereNotIn('role_new', array_map(fn($r) => $r->value, ProjectOrganizationRole::cases()))
            ->orWhereNull('role_new')
            ->get();

        foreach ($invalidRoles as $record) {
            $this->issues[] = "Некорректная роль в project_organization: project_id={$record->project_id}, org_id={$record->organization_id}, role='{$record->role_new}'";

            if ($this->option('fix')) {
                // Пытаемся смаппить старые роли
                $newRole = $this->mapOldRole($record->role ?? $record->role_new);

                if ($newRole) {
                    DB::table('project_organization')
                        ->where('project_id', $record->project_id)
                        ->where('organization_id', $record->organization_id)
                        ->update([
                            'role_new' => $newRole,
                            'updated_at' => now(),
                        ]);

                    $this->fixed[] = "Исправлена роль для org {$record->organization_id} в проекте {$record->project_id}";
                    $this->line("  ✅ Исправлено: роль изменена на '{$newRole}'");
                }
            }
        }

        if ($invalidRoles->isEmpty()) {
            $this->line('  ✅ Все роли корректны');
        } else {
            $this->warn("  ⚠️  Найдено некорректных ролей: " . $invalidRoles->count());
        }
    }

    protected function checkCapabilitiesVsRoles(): void
    {
        $this->info('📋 Проверка capabilities vs roles...');

        $records = DB::table('project_organization')
            ->join('organizations', 'project_organization.organization_id', '=', 'organizations.id')
            ->select('project_organization.*', 'organizations.capabilities', 'organizations.name as org_name')
            ->where('project_organization.is_active', true)
            ->get();

        $incompatible = 0;

        foreach ($records as $record) {
            if (!$record->role_new || $record->role_new === 'owner' || $record->role_new === 'observer') {
                continue; // Owner и observer могут иметь любые capabilities
            }

            $org = Organization::find($record->organization_id);
            $role = ProjectOrganizationRole::tryFrom($record->role_new);

            if (!$org || !$role) {
                continue;
            }

            $validation = $this->organizationProfileService->validateCapabilitiesForRole($org, $role);

            if (!$validation->isValid) {
                $incompatible++;
                $this->issues[] = "Несовместимость capabilities для org '{$record->org_name}' (#{$record->organization_id}) с ролью '{$record->role_new}' в проекте #{$record->project_id}";

                if ($this->option('verbose')) {
                    $this->line("  ⚠️  {$record->org_name}: " . implode(', ', $validation->errors));
                }
            }
        }

        if ($incompatible === 0) {
            $this->line('  ✅ Все capabilities совместимы с ролями');
        } else {
            $this->warn("  ⚠️  Найдено несовместимостей: {$incompatible}");
            $this->line("  💡 Рекомендуется обновить capabilities организаций или изменить их роли");
        }
    }

    protected function checkOrphanedRecords(): void
    {
        $this->info('📋 Проверка orphaned записей...');

        // Записи с несуществующими проектами
        $orphanedProjects = DB::table('project_organization')
            ->leftJoin('projects', 'project_organization.project_id', '=', 'projects.id')
            ->whereNull('projects.id')
            ->count();

        // Записи с несуществующими организациями
        $orphanedOrgs = DB::table('project_organization')
            ->leftJoin('organizations', 'project_organization.organization_id', '=', 'organizations.id')
            ->whereNull('organizations.id')
            ->count();

        if ($orphanedProjects > 0) {
            $this->issues[] = "Найдено {$orphanedProjects} записей с несуществующими проектами";
            $this->warn("  ⚠️  Orphaned project records: {$orphanedProjects}");

            if ($this->option('fix')) {
                DB::table('project_organization')
                    ->leftJoin('projects', 'project_organization.project_id', '=', 'projects.id')
                    ->whereNull('projects.id')
                    ->delete();

                $this->fixed[] = "Удалено {$orphanedProjects} orphaned project records";
                $this->line("  ✅ Удалено orphaned записей");
            }
        }

        if ($orphanedOrgs > 0) {
            $this->issues[] = "Найдено {$orphanedOrgs} записей с несуществующими организациями";
            $this->warn("  ⚠️  Orphaned organization records: {$orphanedOrgs}");

            if ($this->option('fix')) {
                DB::table('project_organization')
                    ->leftJoin('organizations', 'project_organization.organization_id', '=', 'organizations.id')
                    ->whereNull('organizations.id')
                    ->delete();

                $this->fixed[] = "Удалено {$orphanedOrgs} orphaned organization records";
                $this->line("  ✅ Удалено orphaned записей");
            }
        }

        if ($orphanedProjects === 0 && $orphanedOrgs === 0) {
            $this->line('  ✅ Orphaned записи не найдены');
        }
    }

    protected function checkDuplicates(): void
    {
        $this->info('📋 Проверка дубликатов...');

        $duplicates = DB::table('project_organization')
            ->select('project_id', 'organization_id', DB::raw('COUNT(*) as count'))
            ->groupBy('project_id', 'organization_id')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $this->issues[] = "Дубликат: project_id={$duplicate->project_id}, org_id={$duplicate->organization_id}, count={$duplicate->count}";
            $this->warn("  ⚠️  Дубликат найден: project {$duplicate->project_id}, org {$duplicate->organization_id} ({$duplicate->count} записей)");

            if ($this->option('fix')) {
                // Оставляем только самую новую запись
                $records = DB::table('project_organization')
                    ->where('project_id', $duplicate->project_id)
                    ->where('organization_id', $duplicate->organization_id)
                    ->orderBy('created_at', 'desc')
                    ->get();

                $toKeep = $records->first()->id;

                DB::table('project_organization')
                    ->where('project_id', $duplicate->project_id)
                    ->where('organization_id', $duplicate->organization_id)
                    ->where('id', '!=', $toKeep)
                    ->delete();

                $this->fixed[] = "Удалены дубликаты для project {$duplicate->project_id}, org {$duplicate->organization_id}";
                $this->line("  ✅ Оставлена только самая новая запись");
            }
        }

        if ($duplicates->isEmpty()) {
            $this->line('  ✅ Дубликаты не найдены');
        }
    }

    protected function checkActiveFlags(): void
    {
        $this->info('📋 Проверка is_active флагов...');

        $nullFlags = DB::table('project_organization')
            ->whereNull('is_active')
            ->count();

        if ($nullFlags > 0) {
            $this->issues[] = "Найдено {$nullFlags} записей с NULL is_active";
            $this->warn("  ⚠️  NULL is_active flags: {$nullFlags}");

            if ($this->option('fix')) {
                DB::table('project_organization')
                    ->whereNull('is_active')
                    ->update(['is_active' => true]);

                $this->fixed[] = "Исправлено {$nullFlags} NULL is_active флагов";
                $this->line("  ✅ Установлены is_active = true");
            }
        } else {
            $this->line('  ✅ Все is_active флаги установлены');
        }
    }

    protected function mapOldRole(?string $oldRole): ?string
    {
        return match ($oldRole) {
            'child_contractor' => 'subcontractor',
            'collaborator' => 'contractor',
            default => null,
        };
    }

    protected function displaySummary(): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 ИТОГИ ВАЛИДАЦИИ');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (count($this->issues) === 0) {
            $this->info('✅ Все проверки пройдены успешно!');
            $this->line('   Система Project-Based RBAC в идеальном состоянии.');
        } else {
            $this->error('⚠️  Найдено проблем: ' . count($this->issues));

            if ($this->option('fix') && count($this->fixed) > 0) {
                $this->info('✅ Исправлено: ' . count($this->fixed));
                $this->newLine();
                $this->line('Исправления:');
                foreach ($this->fixed as $fix) {
                    $this->line("  • {$fix}");
                }
            } else {
                $this->newLine();
                $this->line('💡 Запустите с опцией --fix для автоматического исправления');
            }
        }

        $this->newLine();
    }
}
