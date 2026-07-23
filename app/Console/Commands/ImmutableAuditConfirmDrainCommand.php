<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ImmutableAuditConfirmDrainCommand extends Command
{
    protected $signature = 'immutable-audit:confirm-drain';

    protected $description = 'Подтверждает остановку всех writer-процессов перед Phase B';

    public function handle(ImmutableAuditRolloutService $rollout): int
    {
        $rollout->confirmDrain(DB::connection(), (bool) config('legal_archive.audit_phase_b_cutover_enabled', false));
        $this->info('Immutable audit writer drain confirmed.');

        return self::SUCCESS;
    }
}
