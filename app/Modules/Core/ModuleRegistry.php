<?php

namespace App\Modules\Core;

use App\Models\Module;
use App\Modules\Contracts\ModuleInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
class ModuleRegistry 
{
    private const CACHE_KEY = 'modules_registry';
    private const CACHE_TTL = 3600; // 1 час
    
    private Collection $modules;
    
    public function __construct()
    {
        $this->modules = collect();
    }
    
    public function loadModules(): Collection 
    {
        if ($this->modules->isEmpty()) {
            $this->modules = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return Module::where('is_active', true)
                    ->orderBy('display_order')
                    ->orderBy('name')
                    ->get();
            });
        }
        
        return $this->modules;
    }
    
    public function getModule(string $slug): ?Module 
    {
        return $this->loadModules()->where('slug', $slug)->first();
    }
    
    public function getModulesByType(string $type): Collection 
    {
        return $this->loadModules()->where('type', $type);
    }
    
    public function getModulesByCategory(string $category): Collection 
    {
        return $this->loadModules()->where('category', $category);
    }
    
    public function getModuleInstance(string $slug): ?ModuleInterface
    {
        $module = $this->getModule($slug);
        
        if (!$module || !$module->class_name) {
            return null;
        }
        
        if (!class_exists($module->class_name)) {
            Log::error("Module class not found: {$module->class_name}");
            return null;
        }
        
        try {
            $instance = app($module->class_name);
            
            if (!$instance instanceof ModuleInterface) {
                Log::error("Module class does not implement ModuleInterface: {$module->class_name}");
                return null;
            }
            
            return $instance;
        } catch (\Exception $e) {
            Log::error("Failed to instantiate module: {$module->class_name}", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function refreshRegistry(): void 
    {
        Cache::forget(self::CACHE_KEY);
        $this->modules = collect();
        
        // Перезапускаем сканирование модулей
        app(ModuleScanner::class)->scanAndRegister();
    }
    
    public function getAllModules(): Collection
    {
        return $this->loadModules();
    }
    
    public function isModuleActive(string $slug): bool 
    {
        return $this->loadModules()->where('slug', $slug)->where('is_active', true)->isNotEmpty();
    }
}
