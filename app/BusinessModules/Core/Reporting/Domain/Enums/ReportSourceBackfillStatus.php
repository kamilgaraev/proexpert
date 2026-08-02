<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSourceBackfillStatus: string
{
    case DISCOVERED = 'discovered';
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case VERIFYING = 'verifying';
    case READY = 'ready';
    case RETRYABLE_FAILED = 'retryable_failed';
    case TERMINAL_FAILED = 'terminal_failed';
    case BLOCKED = 'blocked';
    case STALE = 'stale';
}
