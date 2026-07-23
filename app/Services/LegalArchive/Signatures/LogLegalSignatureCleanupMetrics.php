<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use Illuminate\Support\Facades\Log;

final class LogLegalSignatureCleanupMetrics implements LegalSignatureCleanupMetrics
{
    public function increment(string $metric, array $labels = []): void
    {
        Log::info('legal_signature_cleanup_metric', ['metric' => $metric, 'labels' => $labels]);
    }
}
