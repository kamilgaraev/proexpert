<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

interface LegalSignatureCleanupMetrics
{
    public function increment(string $metric, array $labels = []): void;
}
