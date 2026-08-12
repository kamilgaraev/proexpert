<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentProcessingOutcome
{
    /** @param array{included: int, ready: int, needs_user_action: int, system_failed: int, processing: int, excluded: int} $counts */
    public function __construct(
        public string $type,
        public string $documentStatus,
        public int $processedPages,
        public array $counts,
        public ?string $errorCode = null,
        public ?string $errorMessageKey = null,
        public bool $retryAllowed = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'counts' => $this->counts,
            'retry_allowed' => $this->retryAllowed,
        ];
    }
}
