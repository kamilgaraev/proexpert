<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use RuntimeException;
use Throwable;

final class LegalDocumentCreateFailed extends RuntimeException
{
    public function __construct(
        public readonly LegalArchiveDocument $document,
        public readonly bool $repeatCreateRequired,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
