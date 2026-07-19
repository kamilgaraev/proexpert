<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRolloutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImmutableAuditRepairInvariantsCommand extends Command
{
    protected $signature = 'immutable-audit:repair-invariants {--confirm-repair}';

    protected $description = 'Восстановить и проверить постоянные инварианты неизменяемого аудита';

    public function handle(ImmutableAuditRolloutService $rollout): int
    {
        try {
            if (! $this->option('confirm-repair') || getenv('LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED') !== 'true') {
                $this->error('Immutable audit invariant repair requires an explicit deployment fence.');

                return self::FAILURE;
            }
            $rollout->repairPermanentInvariants(
                DB::connection(),
                true,
                (string) config('legal_archive.audit_writer_secret', ''),
                (int) config('legal_archive.audit_phase_b_drain_ttl_minutes', 15),
            );
            $this->info('Immutable audit invariants repaired and verified.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Immutable audit invariant repair failed.');

            return self::FAILURE;
        }
    }
}
