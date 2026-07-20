<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditWriterReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ImmutableAuditWriterReadinessCommand extends Command
{
    protected $signature = 'immutable-audit:writer-readiness';

    protected $description = 'Проверяет готовность HTTP- и worker-процессов к записи аудита';

    public function handle(ImmutableAuditWriterReadinessService $readiness): int
    {
        $status = $readiness->status(DB::connection(), (string) config('legal_archive.audit_writer_secret', ''));
        $this->line(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $status['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
