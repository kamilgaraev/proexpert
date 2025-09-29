<?php

namespace App\Console\Commands;

use App\Modules\Core\ModuleScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ModulesScanCommand extends Command
{
    protected $signature = 'modules:scan {--clear-cache : Clear module cache before scanning}';
    
    protected $description = 'Scan and register all modules from configuration files';
    
    public function handle(ModuleScanner $moduleScanner): int
    {
        $this->info('Scanning modules...');
        
        if ($this->option('clear-cache')) {
            Cache::forget('modules_registry');
            $this->info('Module cache cleared.');
        }
        
        try {
            $modules = $moduleScanner->scanAndRegister();
            
            $this->info("Successfully scanned and registered {$modules->count()} modules:");
            
            foreach ($modules as $module) {
                $status = $this->checkModuleStatus($module);
                $this->line("  - {$module['name']} ({$module['slug']}) - {$status}");
            }
            
            $this->newLine();
            $this->info('Module scanning completed successfully!');
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Failed to scan modules: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
            
            return self::FAILURE;
        }
    }
    
    private function checkModuleStatus(array $moduleConfig): string
    {
        $className = $moduleConfig['class_name'] ?? null;
        
        if (!$className) {
            return '<fg=red>No class name</>';
        }
        
        if (!class_exists($className)) {
            return '<fg=red>Class not found</>';
        }
        
        return '<fg=green>OK</>';
    }
}