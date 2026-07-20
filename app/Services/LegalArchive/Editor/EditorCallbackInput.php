<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

final readonly class EditorCallbackInput
{
    public function __construct(
        public string $sessionId,
        public string $documentKey,
        public int $status,
        public ?string $downloadUrl,
        public string $replayToken,
        public string $token,
    ) {}

    public function requiresSave(): bool
    {
        return in_array($this->status, [2, 6], true);
    }
}
