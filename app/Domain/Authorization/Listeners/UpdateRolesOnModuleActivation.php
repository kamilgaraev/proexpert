<?php

namespace App\Domain\Authorization\Listeners;

use App\Domain\Authorization\Services\RoleUpdater;
use App\Modules\Events\ModuleActivated;
use App\Modules\Events\ModuleDeactivated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateRolesOnModuleActivation implements ShouldQueue
{
    use InteractsWithQueue;

    protected RoleUpdater $roleUpdater;

    public function __construct(RoleUpdater $roleUpdater)
    {
        $this->roleUpdater = $roleUpdater;
    }

    public function handle(ModuleActivated $event): void
    {
        try {
            $this->roleUpdater->updateRolesForModule($event->moduleSlug);
            
            Log::info("Роли обновлены для активированного модуля: {$event->moduleSlug}", [
                'organization_id' => $event->organizationId,
                'module_slug' => $event->moduleSlug
            ]);
            
        } catch (\Exception $e) {
            Log::error("Ошибка обновления ролей для модуля {$event->moduleSlug}: " . $e->getMessage(), [
                'organization_id' => $event->organizationId,
                'exception' => $e
            ]);
        }
    }
}
