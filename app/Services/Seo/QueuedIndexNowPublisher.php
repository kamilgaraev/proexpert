<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Contracts\Seo\IndexNowPublisher;
use App\Jobs\Seo\SubmitUrlsToIndexNow;

final class QueuedIndexNowPublisher implements IndexNowPublisher
{
    public function publish(array $urls): void
    {
        SubmitUrlsToIndexNow::dispatch($urls);
    }
}
