<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Organization;
use App\Services\OneCExchange\OneCExchangeIncidentService;
use Illuminate\Console\Command;

final class NotifyOneCExchangeIncidentsCommand extends Command
{
    protected $signature = 'one-c-exchange:notify-incidents
        {--organization-id= : Notify only one organization}
        {--window-hours=24 : Monitoring window in hours}';

    protected $description = 'Send notifications for critical 1C exchange incidents';

    public function handle(OneCExchangeIncidentService $incidents, ModulePermissionChecker $modules): int
    {
        $query = Organization::query()->where('is_active', true);
        $organizationId = $this->option('organization-id');

        if ($organizationId !== null && $organizationId !== '') {
            $query->whereKey((int) $organizationId);
        }

        $windowHours = max(1, min(720, (int) $this->option('window-hours')));
        $sent = 0;
        $skipped = 0;

        $query->select('id')->chunkById(100, function ($organizations) use ($incidents, $modules, $windowHours, &$sent, &$skipped): void {
            foreach ($organizations as $organization) {
                if (!$modules->isModuleActive('one-c-basic-exchange', (int) $organization->id)) {
                    $skipped++;
                    continue;
                }

                $result = $incidents->notify((int) $organization->id, [
                    'window_hours' => $windowHours,
                ]);

                $sent += (int) $result['sent_count'];
                $skipped += (int) $result['skipped_count'];
            }
        });

        $this->info("1C exchange incident notifications sent: {$sent}, skipped: {$skipped}");

        return self::SUCCESS;
    }
}
