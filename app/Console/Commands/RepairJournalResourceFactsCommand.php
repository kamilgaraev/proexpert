<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CompletedWork\CompletedWorkFactService;
use Illuminate\Console\Command;

class RepairJournalResourceFactsCommand extends Command
{
    protected $signature = 'journal:repair-resource-facts {organizationId?}';

    protected $description = 'Repair completed work facts for journal materials, equipment and workers linked to estimate items';

    public function __construct(
        private readonly CompletedWorkFactService $completedWorkFactService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizationId = $this->argument('organizationId');
        $syncedCount = $this->completedWorkFactService->repairJournalResourceFacts(
            $organizationId !== null ? (int) $organizationId : null,
        );

        $this->info("Journal resource facts repaired: {$syncedCount}");

        return self::SUCCESS;
    }
}
