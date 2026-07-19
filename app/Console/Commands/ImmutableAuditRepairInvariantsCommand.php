<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditPhaseBInvariantService;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class ImmutableAuditRepairInvariantsCommand extends Command
{
    protected $signature = 'immutable-audit:repair-invariants {--confirm-repair}';

    protected $description = 'Восстановить и проверить постоянные инварианты неизменяемого аудита';

    public function handle(DatabaseManager $database, ImmutableAuditPhaseBInvariantService $invariants): int
    {
        try {
            if (! $this->option('confirm-repair') || getenv('LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED') !== 'true') {
                $this->error('Immutable audit invariant repair requires an explicit deployment fence.');

                return self::FAILURE;
            }
            $invariants->repairPermanentInvariants($database->connection());
            $this->info('Immutable audit invariants repaired and verified.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            report($error);
            $this->error('Immutable audit invariant repair failed.');

            return self::FAILURE;
        }
    }
}
