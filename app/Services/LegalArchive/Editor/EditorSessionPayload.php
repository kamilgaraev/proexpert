<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;

final readonly class EditorSessionPayload implements Arrayable
{
    public function __construct(
        public bool $enabled,
        public string $mode,
        public string $documentKey,
        public string $documentKeyPrefix,
        public ?string $serverUrl,
        public ?string $token,
        public array $configuration,
        public DateTimeImmutable $expiresAt,
        public ?string $viewerUrl = null,
        public ?string $disabledReason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'mode' => $this->mode,
            'document_key' => $this->documentKey,
            'document_key_prefix' => $this->documentKeyPrefix,
            'server_url' => $this->serverUrl,
            'token' => $this->token,
            'configuration' => $this->configuration,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            'viewer_url' => $this->viewerUrl,
            'disabled_reason' => $this->disabledReason,
        ];
    }
}
