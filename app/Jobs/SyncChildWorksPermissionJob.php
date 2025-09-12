<?php

namespace App\Jobs;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncChildWorksPermissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public bool $force = false) {}

    public function handle(): void
    {
        // В новой системе права определяются в JSON файлах ролей
        // Данный Job больше не нужен, так как синхронизация происходит автоматически
        
        Log::info('[SyncChildWorksPermissionJob] Skipped - new authorization system in use');
        return;
        
        /* Старая логика закомментирована для совместимости
        Log::info('[SyncChildWorksPermissionJob] started', ['force' => $this->force]);

        $permission = Permission::firstOrCreate(
            ['slug' => 'projects.view_child_works'],
            ['name' => 'Просмотр работ дочерних организаций', 'description' => 'Доступ к сквозному списку работ', 'group' => 'projects']
        );

        $targetRoleSlugs = [
            Role::ROLE_OWNER,
            Role::ROLE_ADMIN,
            Role::ROLE_ACCOUNTANT,
        ];

        $roles = Role::whereIn('slug', $targetRoleSlugs)->get();
        foreach ($roles as $role) {
            if ($this->force || !$role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
                Log::info('[SyncChildWorksPermissionJob] synced', ['role' => $role->slug]);
            }
        }

        Log::info('[SyncChildWorksPermissionJob] finished');
        */
    }
} 