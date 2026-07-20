<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use Closure;

final readonly class LegalDocumentVersionAttempt
{
    private Closure $ownershipAssertion;

    private ?Closure $heartbeatCallback;

    private ?Closure $completionCallback;

    public function __construct(
        public string $operationId,
        public string $attemptToken,
        callable $ownershipAssertion,
        ?callable $heartbeatCallback = null,
        ?callable $completionCallback = null,
    ) {
        if ($operationId === '' || mb_strlen($operationId) > 191
            || $attemptToken === '' || mb_strlen($attemptToken) > 191
        ) {
            throw new LegalDocumentVersionLeaseLost;
        }

        $this->ownershipAssertion = Closure::fromCallable($ownershipAssertion);
        $this->heartbeatCallback = $heartbeatCallback === null ? null : Closure::fromCallable($heartbeatCallback);
        $this->completionCallback = $completionCallback === null ? null : Closure::fromCallable($completionCallback);
    }

    public function assertOwned(LegalArchiveDocument $document): void
    {
        ($this->ownershipAssertion)($document, $this->attemptToken);
    }

    public function heartbeat(): void
    {
        if ($this->heartbeatCallback !== null) {
            ($this->heartbeatCallback)($this->attemptToken);
        }
    }

    public function complete(LegalArchiveDocumentVersion $version): void
    {
        if ($this->completionCallback !== null) {
            ($this->completionCallback)($version, $this->attemptToken);
        }
    }
}
