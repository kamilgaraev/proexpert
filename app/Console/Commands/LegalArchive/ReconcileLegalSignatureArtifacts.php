<?php

declare(strict_types=1);

namespace App\Console\Commands\LegalArchive;

use App\Services\LegalArchive\Signatures\LegalSignatureArtifactReconciler;
use Illuminate\Console\Command;

final class ReconcileLegalSignatureArtifacts extends Command
{
    protected $signature = 'legal-signatures:reconcile-artifacts {--limit=100}';

    protected $description = 'Recover interrupted legal signature artifact operations';

    public function handle(LegalSignatureArtifactReconciler $service): int
    {
        $this->info((string) $service->reconcile(max(1, min((int) $this->option('limit'), 500))));

        return self::SUCCESS;
    }
}
