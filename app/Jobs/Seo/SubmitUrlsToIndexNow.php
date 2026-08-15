<?php

declare(strict_types=1);

namespace App\Jobs\Seo;

use App\Services\Seo\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SubmitUrlsToIndexNow implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public int $uniqueFor = 600;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(public readonly array $urls) {}

    public function handle(IndexNowService $service): void
    {
        $service->submit($this->urls);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return hash('sha256', implode("\n", $this->urls));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('IndexNow URL submission failed', [
            'url_count' => count($this->urls),
            'exception_class' => $exception::class,
        ]);
    }
}
