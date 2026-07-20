<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\Files\LegalDocumentFileCleanupDebtService;
use Illuminate\Console\Command;

final class CleanupLegalDocumentFileStorage extends Command
{
    protected $signature = 'legal-documents:cleanup-file-storage {--limit=100}';

    protected $description = 'Retry cleanup for uncommitted legal document files';

    public function handle(LegalDocumentFileCleanupDebtService $service): int
    {
        $this->info((string) $service->processDue(max(1, min((int) $this->option('limit'), 500))));

        return self::SUCCESS;
    }
}
