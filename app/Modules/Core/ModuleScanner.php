<?php

namespace App\Modules\Core;

use App\Models\Module;
use App\Modules\Events\ModuleDiscovered;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Log;
class ModuleScanner 
{
    public function scanAndRegister(): Collection 
    {
        $modules = $this->loadFromConfigFiles();
        
        foreach ($modules as $moduleConfig) {
            $this->registerModule($moduleConfig);
        }
        
        return collect($modules);
    }
    
    private function loadFromConfigFiles(): array 
    {
        $modules = [];
        $configPath = config_path('ModuleList');
        
        if (!is_dir($configPath)) {
            return $modules;
        }
        
        // Рекурсивно сканируем все JSON файлы
        $finder = new Finder();
        $finder->files()->name('*.json')->in($configPath);
        
        foreach ($finder as $file) {
            try {
                $config = json_decode($file->getContents(), true);
                
                if ($this->isValidConfig($config)) {
                    $config['config_file'] = $file->getRelativePathname();
                    $modules[] = $config;
                }
            } catch (\Exception $e) {
                // Логируем ошибку парсинга конфигурации
                Log::warning("Failed to parse module config: {$file->getPathname()}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $modules;
    }
    
    private function isValidConfig(array $config): bool 
    {
        $required = ['name', 'slug', 'version', 'type', 'billing_model', 'class_name'];
        
        foreach ($required as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }
        
        // Проверяем, что класс модуля существует
        if (!class_exists($config['class_name'])) {
            Log::warning("Module class not found: {$config['class_name']}");
            return false;
        }
        
        return true;
    }
    
    private function registerModule(array $config): void 
    {
        // Автоматически создаем/обновляем запись в БД
        Module::updateOrCreate(
            ['slug' => $config['slug']],
            [
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'],
                'billing_model' => $config['billing_model'],
                'category' => $config['category'] ?? 'general',
                'description' => $config['description'] ?? '',
                'pricing_config' => $config['pricing'] ?? [],
                'permissions' => $config['permissions'] ?? [],
                'features' => $config['features'] ?? [],
                'dependencies' => $config['dependencies'] ?? [],
                'conflicts' => $config['conflicts'] ?? [],
                'limits' => $config['limits'] ?? [],
                'is_active' => $config['is_active'] ?? true,
                'class_name' => $config['class_name'],
                'config_file' => $config['config_file'],
                'icon' => $config['icon'] ?? null,
                'display_order' => $config['display_order'] ?? 0,
                'last_scanned_at' => now()
            ]
        );
        
        event(new ModuleDiscovered($config['slug']));
    }
}
