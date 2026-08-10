<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use RuntimeException;

final class DocumentManifestNeedsReview extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly array $safeContext = [],
    ) {
        parent::__construct($safeCode);
    }
}
