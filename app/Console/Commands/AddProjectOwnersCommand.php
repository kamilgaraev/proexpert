<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Project;

class AddProjectOwnersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'projects:add-owners 
                            {--dry-run : Показать что будет сделано, но не выполнять}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Добавить owner организацию в project_organization для каждого проекта';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - изменения не будут применены');
        }
        
        $this->info('🚀 Начинаем добавление owners...');
        
        // Получить все проекты
        $projects = Project::select('id', 'organization_id', 'created_at')->get();
        
        $this->info("Найдено проектов: {$projects->count()}");
        
        $added = 0;
        $skipped = 0;
        
        DB::transaction(function() use ($projects, $dryRun, &$added, &$skipped) {
            foreach ($projects as $project) {
                // Проверить существует ли уже owner в project_organization
                $exists = DB::table('project_organization')
                    ->where('project_id', $project->id)
                    ->where('organization_id', $project->organization_id)
                    ->exists();
                
                if ($exists) {
                    $skipped++;
                    continue;
                }
                
                if (!$dryRun) {
                    // Добавить owner
                    DB::table('project_organization')->insert([
                        'project_id' => $project->id,
                        'organization_id' => $project->organization_id,
                        'role' => 'owner',
                        'role_new' => 'owner', // Используем новую колонку
                        'is_active' => true,
                        'invited_at' => $project->created_at,
                        'accepted_at' => $project->created_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                
                $added++;
                
                if ($added % 50 === 0) {
                    $this->info("Обработано: {$added}...");
                }
            }
        });
        
        $this->newLine();
        $this->info("✅ Готово!");
        $this->info("Добавлено: {$added}");
        $this->info("Пропущено (уже существует): {$skipped}");
        
        if ($dryRun) {
            $this->warn('⚠️  Это был DRY RUN - ничего не изменено в БД');
            $this->info('Для применения изменений запустите команду без флага --dry-run');
        }
        
        return self::SUCCESS;
    }
}
