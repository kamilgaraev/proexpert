<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Enums\ProjectOrganizationRole;

class MigrateProjectRoles extends Command
{
    protected $signature = 'rbac:migrate-roles 
                          {--dry-run : Показать что будет изменено, но не выполнять}
                          {--force : Пропустить подтверждение}';

    protected $description = 'Миграция старых ролей project_organization в новый формат';

    protected array $roleMapping = [
        'child_contractor' => 'subcontractor',
        'collaborator' => 'contractor',
        'main_contractor' => 'general_contractor',
        'client' => 'customer',
    ];

    public function handle(): int
    {
        $this->info('🔄 Миграция ролей project_organization...');
        $this->newLine();

        // Проверяем текущее состояние
        $stats = $this->analyzeCurrentState();
        $this->displayStats($stats);

        if ($stats['total'] === 0) {
            $this->info('✅ Миграция не требуется - все роли уже в новом формате');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('🔍 DRY RUN режим - изменения не будут применены');
            $this->displayMigrationPlan($stats);
            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            if (!$this->confirm('Выполнить миграцию ролей?', true)) {
                $this->info('Операция отменена.');
                return self::SUCCESS;
            }
        }

        $this->performMigration($stats);

        return self::SUCCESS;
    }

    protected function analyzeCurrentState(): array
    {
        $stats = [
            'total' => 0,
            'by_role' => [],
            'records' => [],
        ];

        // Получаем все записи с role или role_new не в новом формате
        $validRoles = array_map(fn($r) => $r->value, ProjectOrganizationRole::cases());

        $records = DB::table('project_organization')
            ->select('id', 'project_id', 'organization_id', 'role', 'role_new')
            ->where(function ($query) use ($validRoles) {
                $query->whereNotIn('role_new', $validRoles)
                      ->orWhereNull('role_new')
                      ->orWhere(function ($q) {
                          $q->whereColumn('role', '!=', 'role_new');
                      });
            })
            ->get();

        foreach ($records as $record) {
            $oldRole = $record->role_new ?? $record->role;
            $newRole = $this->mapRole($oldRole);

            if ($newRole) {
                $stats['total']++;
                $stats['by_role'][$oldRole] = ($stats['by_role'][$oldRole] ?? 0) + 1;
                $stats['records'][] = [
                    'id' => $record->id,
                    'project_id' => $record->project_id,
                    'organization_id' => $record->organization_id,
                    'old_role' => $oldRole,
                    'new_role' => $newRole,
                ];
            }
        }

        return $stats;
    }

    protected function displayStats(array $stats): void
    {
        $this->info('📊 Текущее состояние:');
        $this->line('  Записей требующих миграции: ' . $stats['total']);
        
        if ($stats['total'] > 0) {
            $this->newLine();
            $this->line('  Распределение по старым ролям:');
            foreach ($stats['by_role'] as $role => $count) {
                $newRole = $this->mapRole($role);
                $this->line("    • '{$role}' → '{$newRole}': {$count} записей");
            }
        }

        $this->newLine();
    }

    protected function displayMigrationPlan(array $stats): void
    {
        $this->newLine();
        $this->info('📋 План миграции:');
        $this->newLine();

        $bar = $this->output->createProgressBar(min($stats['total'], 10));
        $bar->start();

        foreach (array_slice($stats['records'], 0, 10) as $record) {
            $this->line(sprintf(
                "  Project #%d, Org #%d: '%s' → '%s'",
                $record['project_id'],
                $record['organization_id'],
                $record['old_role'],
                $record['new_role']
            ));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($stats['total'] > 10) {
            $this->line("  ... и еще " . ($stats['total'] - 10) . " записей");
        }

        $this->newLine();
    }

    protected function performMigration(array $stats): void
    {
        $this->info('⚙️  Выполнение миграции...');
        
        $bar = $this->output->createProgressBar($stats['total']);
        $bar->start();

        $updated = 0;
        $failed = 0;

        DB::beginTransaction();

        try {
            foreach ($stats['records'] as $record) {
                try {
                    DB::table('project_organization')
                        ->where('id', $record['id'])
                        ->update([
                            'role' => $record['new_role'],
                            'role_new' => $record['new_role'],
                            'updated_at' => now(),
                        ]);
                    
                    $updated++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->newLine();
                    $this->error("  Ошибка для записи #{$record['id']}: " . $e->getMessage());
                }
                
                $bar->advance();
            }

            DB::commit();

            $bar->finish();
            $this->newLine();
            $this->newLine();

            $this->info('✅ Миграция завершена!');
            $this->line("  Обновлено: {$updated}");
            if ($failed > 0) {
                $this->warn("  Ошибок: {$failed}");
            }

            // Финальная проверка
            $this->newLine();
            $this->info('🔍 Финальная проверка...');
            $remainingStats = $this->analyzeCurrentState();
            
            if ($remainingStats['total'] === 0) {
                $this->info('✅ Все роли успешно мигрированы!');
            } else {
                $this->warn("⚠️  Осталось записей для миграции: {$remainingStats['total']}");
                $this->line('  Возможно, требуется ручная проверка');
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine();
            $this->newLine();
            $this->error('❌ Ошибка при миграции: ' . $e->getMessage());
            $this->line('  Все изменения отменены.');
            return;
        }
    }

    protected function mapRole(?string $oldRole): ?string
    {
        if (!$oldRole) {
            return null;
        }

        // Если роль уже в новом формате, возвращаем её
        if (ProjectOrganizationRole::tryFrom($oldRole)) {
            return $oldRole;
        }

        // Используем маппинг
        return $this->roleMapping[$oldRole] ?? null;
    }
}
