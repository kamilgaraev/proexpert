<?php

namespace App\Domain\Authorization\Listeners;

use App\Domain\Authorization\Services\RoleUpdater;
use App\Modules\Events\ModuleDeactivated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateRolesOnModuleDeactivation implements ShouldQueue
{
    use InteractsWithQueue;

    protected RoleUpdater $roleUpdater;

    public function __construct(RoleUpdater $roleUpdater)
    {
        $this->roleUpdater = $roleUpdater;
    }

    public function handle(ModuleDeactivated $event): void
    {
        try {
            $this->roleUpdater->removeRolesForModule($event->moduleSlug);
            
            Log::info("Права модуля удалены из ролей при деактивации: {$event->moduleSlug}", [
                'organization_id' => $event->organizationId,
                'module_slug' => $event->moduleSlug
            ]);
            
        } catch (\Exception $e) {
            Log::error("Ошибка удаления прав модуля {$event->moduleSlug} из ролей: " . $e->getMessage(), [
                'organization_id' => $event->organizationId,
                'exception' => $e
            ]);
        }
    }
}
