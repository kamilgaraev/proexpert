<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ImmutableAuditPhaseBCutoverCommand extends Command
{
    protected $signature = 'immutable-audit:phase-b-cutover {--confirm-writer-version=}';

    protected $description = 'Переключает аудит на Phase B после подтверждения версии всех writer-процессов';

    public function handle(ImmutableAuditRolloutService $rollout): int
    {
        $rollout->cutover(DB::connection(), (bool) config('legal_archive.audit_phase_b_cutover_enabled', false), (int) $this->option('confirm-writer-version'));
        $this->info('Immutable audit Phase B completed.');

        return self::SUCCESS;
    }
}
