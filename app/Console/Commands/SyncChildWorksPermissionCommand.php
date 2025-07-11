<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncChildWorksPermissionJob;

class SyncChildWorksPermissionCommand extends Command
{
    protected $signature = 'permissions:sync-child-works {--force : Перезаписать разрешение даже если уже назначено}';

    protected $description = 'Синхронизировать permission projects.view_child_works с ролями Owner, Admin, Accountant';

    public function handle(): int
    {
        $this->info('Dispatching SyncChildWorksPermissionJob...');

        SyncChildWorksPermissionJob::dispatch($this->option('force'));

        $this->info('Job dispatched ✓');
        return self::SUCCESS;
    }
} 