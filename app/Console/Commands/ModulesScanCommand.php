<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Core\ModuleScanner;
use App\Models\Module;
use Illuminate\Support\Facades\Cache;

class ModulesScanCommand extends Command
{
    protected $signature = 'modules:scan {--force : Force rescan even if recently scanned}';
    protected $description = 'Scan and update module configurations from config files';

    protected ModuleScanner $scanner;

    public function __construct(ModuleScanner $scanner)
    {
        parent::__construct();
        $this->scanner = $scanner;
    }

    public function handle(): int
    {
        $force = $this->option('force');
        
        // Проверяем когда последний раз сканировали (если не force)
        if (!$force) {
            $lastScan = Cache::get('modules_last_scan');
            if ($lastScan && $lastScan > now()->subMinutes(10)) {
                $this->info('Modules were scanned recently. Use --force to override.');
                return 0;
            }
        }

        $this->info('Starting module configuration scan...');
        
        try {
            // Запускаем сканирование
            $modules = $this->scanner->scanAndRegister();
            
            $this->info("Successfully scanned {$modules->count()} modules:");
            
            foreach ($modules as $moduleConfig) {
                $this->line("  ✓ {$moduleConfig['name']} ({$moduleConfig['slug']})");
                
                // Показываем количество прав для важных модулей
                if (in_array($moduleConfig['slug'], ['multi-organization', 'users', 'organizations'])) {
                    $permissionsCount = count($moduleConfig['permissions'] ?? []);
                    $this->line("    └─ Permissions: {$permissionsCount}");
                }
            }
            
            // Обновляем время последнего сканирования
            Cache::put('modules_last_scan', now(), 3600);
            
            // Проверяем конкретно модуль мультиорганизации
            $multiOrgModule = Module::where('slug', 'multi-organization')->first();
            if ($multiOrgModule) {
                $reportsPermissions = collect($multiOrgModule->permissions)
                    ->filter(fn($perm) => str_contains($perm, 'reports'))
                    ->count();
                    
                if ($reportsPermissions > 0) {
                    $this->info("✓ Multi-organization module has {$reportsPermissions} reports permissions");
                } else {
                    $this->warn("⚠ Multi-organization module missing reports permissions");
                }
            }
            
            // Очищаем кэш прав доступа
            try {
                // Проверяем поддержку тегов
                if (method_exists(Cache::getStore(), 'tags')) {
                    Cache::tags(['permissions', 'modules'])->flush();
                    $this->info('✓ Tagged permissions cache cleared');
                } else {
                    // Очищаем ключи по отдельности для драйверов без поддержки тегов
                    Cache::forget('module_registry');
                    Cache::forget('module_permissions');
                    Cache::forget('user_permissions_cache');
                    Cache::forget('role_permissions_cache');
                    $this->info('✓ Permissions cache keys cleared');
                }
            } catch (\Exception $e) {
                $this->warn("Cache clearing failed: {$e->getMessage()}");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Module scan failed: {$e->getMessage()}");
            return 1;
        }
    }
}
