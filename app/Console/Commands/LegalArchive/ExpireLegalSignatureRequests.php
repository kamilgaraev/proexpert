<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\Signatures\LegalSignatureExpiryService;
use Illuminate\Console\Command;

final class ExpireLegalSignatureRequests extends Command
{
    protected $signature = 'legal-signatures:expire {--limit=100}';

    protected $description = 'Expire pending legal signature requests';

    public function handle(LegalSignatureExpiryService $service): int
    {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $this->info((string) $service->expireDue($limit));

        return self::SUCCESS;
    }
}
