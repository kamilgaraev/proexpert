<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;

final class ExecutePublicCoreTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $requestRef)
    {
        if (!PublicCoreRuntimeResource::opaqueRef($requestRef)) {
            throw new InvalidArgumentException('public_core_request_invalid');
        }
        $this->onQueue('ai-public-core');
    }

    public function handle(PublicCoreRequestService $requests): void
    {
        $requests->dispatch($this->requestRef);
    }
}
