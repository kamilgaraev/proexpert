<?php

namespace App\Domain\Authorization\Listeners;

use App\Domain\Authorization\Services\RoleUpdater;
use App\Modules\Events\ModuleActivated;
use App\Modules\Events\ModuleDeactivated;
use App\Services\Logging\LoggingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class UpdateRolesOnModuleActivation implements ShouldQueue
{
    use InteractsWithQueue;

    protected RoleUpdater $roleUpdater;
    protected LoggingService $logging;

    public function __construct(RoleUpdater $roleUpdater, LoggingService $logging)
    {
        $this->roleUpdater = $roleUpdater;
        $this->logging = $logging;
    }

    public function handle(ModuleActivated $event): void
    {
        try {
            $updated = $this->roleUpdater->updateRolesForModule($event->moduleSlug);
            
            if ($updated) {
                $this->logging->technical('role.permissions.updated', [
                    'organization_id' => $event->organizationId,
                    'module_slug' => $event->moduleSlug,
                    'action' => 'module_activated'
                ]);
            }
            
        } catch (\Exception $e) {
            $this->logging->technical('role.permissions.update_failed', [
                'organization_id' => $event->organizationId,
                'module_slug' => $event->moduleSlug,
                'error' => $e->getMessage(),
                'hint' => 'Проверьте права на запись файлов config/RoleDefinitions/*.json'
            ], 'warning');
        }
    }
}
