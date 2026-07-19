<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

final readonly class VersionInput
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public ?string $versionNumber = null,
        public ?string $versionLabel = null,
        public ?int $uploadedByUserId = null,
        public ?array $metadata = null,
        public bool $makeCurrent = true,
    ) {}
}
