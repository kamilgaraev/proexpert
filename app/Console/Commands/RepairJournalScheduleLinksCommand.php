<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CompletedWork\CompletedWorkFactService;
use Illuminate\Console\Command;

class RepairJournalScheduleLinksCommand extends Command
{
    protected $signature = 'journal:repair-schedule-links {organizationId?}';

    protected $description = 'Repair journal facts linked to schedule tasks through estimate items';

    public function __construct(
        private readonly CompletedWorkFactService $completedWorkFactService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizationId = $this->argument('organizationId');
        $repairedCount = $this->completedWorkFactService->repairJournalScheduleLinks(
            $organizationId !== null ? (int) $organizationId : null,
        );

        $this->info("Journal schedule links repaired: {$repairedCount}");

        return self::SUCCESS;
    }
}
