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
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function operationId(): ?string
    {
        return is_string($this->document->create_operation_id) ? $this->document->create_operation_id : null;
    }

    public function retryAction(): string
    {
        return (string) ($this->document->source_create_retry_action ?: 'retry_finalize');
    }
}
