<?php

declare(strict_types=1);

namespace App\Jobs\LegalArchive;

use App\Services\LegalArchive\Signatures\LegalSignatureExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireLegalSignatureRequests implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $limit = 100)
    {
        $this->onQueue('legal-signatures');
    }

    public function handle(LegalSignatureExpiryService $service): void
    {
        $service->expireDue($this->limit);
    }
}
