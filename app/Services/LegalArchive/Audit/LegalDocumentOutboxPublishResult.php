<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use Illuminate\Support\Carbon;

final readonly class LegalDocumentOutboxPublishResult
{
    public function __construct(
        public string $status,
        public ?Carbon $retryAt = null,
    ) {}
}
