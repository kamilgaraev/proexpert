<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class SyncProjectContractors extends Command
{
    protected $signature = 'contractors:sync-from-projects 
                            {--dry-run : Показать что будет создано без реального создания}
                            {--project= : Синхронизировать только конкретный проект}';

    protected $description = 'Синхронизирует участников проектов с ролью подрядчик в справочник подрядчиков';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $projectId = $this->option('project');
        
        $this->info('Поиск участников проектов с ролью подрядчик...');
        
        $query = DB::table('project_organization')
            ->join('projects', 'project_organization.project_id', '=', 'projects.id')
            ->join('organizations', 'project_organization.organization_id', '=', 'organizations.id')
            ->whereIn('project_organization.role_new', ['contractor', 'subcontractor'])
            ->where('project_organization.is_active', true)
            ->select(
                'projects.organization_id as owner_org_id',
                'organizations.id as contractor_org_id',
                'organizations.name',
                'organizations.tax_number as inn',
                'organizations.address',
                'organizations.phone',
                'organizations.email',
                'project_organization.role_new as role',
                'projects.id as project_id',
                'projects.name as project_name'
            );
            
        if ($projectId) {
            $query->where('projects.id', $projectId);
        }
        
        $participants = $query->get();
        
        if ($participants->isEmpty()) {
            $this->info('Нет участников проектов с ролью подрядчик.');
            return Command::SUCCESS;
        }
        
        $this->info("Найдено участников: " . $participants->count());
        $this->newLine();
        
        if ($isDryRun) {
            $this->warn('Режим DRY RUN - записи не будут созданы');
            $this->newLine();
        }
        
        $created = 0;
        $exists = 0;
        $errors = 0;
        
        $table = [];
        
        foreach ($participants as $participant) {
            $exists_check = Contractor::where('organization_id', $participant->owner_org_id)
                ->where('source_organization_id', $participant->contractor_org_id)
                ->exists();
                
            $status = $exists_check ? '✓ Существует' : '→ Будет создан';
            
            $table[] = [
                'Проект' => $participant->project_name,
                'Подрядчик' => $participant->name,
                'ИНН' => $participant->inn ?? 'N/A',
                'Роль' => $participant->role === 'contractor' ? 'Подрядчик' : 'Субподрядчик',
                'Статус' => $status,
            ];
            
            if ($exists_check) {
                $exists++;
                continue;
            }
            
            if (!$isDryRun) {
                try {
                    Contractor::create([
                        'organization_id' => $participant->owner_org_id,
                        'source_organization_id' => $participant->contractor_org_id,
                        'name' => $participant->name,
                        'inn' => $participant->inn,
                        'legal_address' => $participant->address,
                        'phone' => $participant->phone,
                        'email' => $participant->email,
                        'contractor_type' => Contractor::TYPE_INVITED_ORGANIZATION,
                        'connected_at' => now(),
                        'sync_settings' => [
                            'sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn'],
                            'sync_interval_hours' => 24,
                        ],
                    ]);
                    $created++;
                    $this->info("✓ Создан подрядчик: {$participant->name}");
                } catch (\Exception $e) {
                    $errors++;
                    $this->error("✗ Ошибка создания {$participant->name}: " . $e->getMessage());
                }
            } else {
                $created++;
            }
        }
        
        $this->newLine();
        $this->table(
            ['Проект', 'Подрядчик', 'ИНН', 'Роль', 'Статус'],
            $table
        );
        
        $this->newLine();
        $this->info("Уже существует: {$exists}");
        
        if ($isDryRun) {
            $this->info("Будет создано: {$created}");
        } else {
            $this->info("Успешно создано: {$created}");
            if ($errors > 0) {
                $this->error("Ошибок: {$errors}");
            }
        }
        
        return Command::SUCCESS;
    }
}

