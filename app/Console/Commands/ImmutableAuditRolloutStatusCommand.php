<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ImmutableAuditRolloutStatusCommand extends Command
{
    protected $signature = 'immutable-audit:rollout-status';

    protected $description = 'Проверяет срок временной compatibility Phase A';

    public function handle(ImmutableAuditRolloutService $rollout): int
    {
        $status = $rollout->status(DB::connection());
        $this->line(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($status['phase'] === null) {
            Log::critical('immutable_audit.rollout_not_installed');

            return self::FAILURE;
        }
        if ($status['overdue']) {
            Log::critical('immutable_audit.phase_a_overdue', ['phase_a_expires_at' => $status['phase_a_expires_at']]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
