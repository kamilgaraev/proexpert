<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\Signatures\LegalSignatureCleanupDebtService;
use Illuminate\Console\Command;

final class CleanupLegalSignatureStorage extends Command
{
    protected $signature = 'legal-signatures:cleanup-storage {--limit=100}';

    protected $description = 'Retry exact-version cleanup for legal signature containers';

    public function handle(LegalSignatureCleanupDebtService $service): int
    {
        $this->info((string) $service->processDue(max(1, min((int) $this->option('limit'), 500))));

        return self::SUCCESS;
    }
}
