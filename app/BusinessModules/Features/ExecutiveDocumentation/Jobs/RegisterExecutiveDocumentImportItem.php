<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Jobs;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RegisterExecutiveDocumentImportItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $backoff = 15;

    public function __construct(public int $itemId, public int $attempt)
    {
        $this->onQueue('imports');
    }

    public function handle(ExecutiveDocumentImportService $service): void
    {
        $service->process($this->itemId, $this->attempt);
    }

    public function failed(\Throwable $exception): void
    {
        app(ExecutiveDocumentImportService::class)->fail($this->itemId, $this->attempt, $exception);
    }
}
