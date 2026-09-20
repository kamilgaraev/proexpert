<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Contract\ContractRevisionDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class GenerateContractRevisionDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $documentGenerationId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ContractRevisionDocumentService $service): void
    {
        if (!$service->generate($this->documentGenerationId)) {
            $this->release(30);
        }
    }
}
