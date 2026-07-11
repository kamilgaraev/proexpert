<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class FailureData
{
    public string $fingerprint;

    /** @param array<string, bool|float|int|string|array<array-key, mixed>|null> $safeContext */
    public function __construct(
        public FailureContext $context,
        public FailureCategory $category,
        public string $code,
        public array $safeContext,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $code) !== 1) {
            throw new InvalidArgumentException('Invalid failure code.');
        }

        $this->fingerprint = $this->buildFingerprint();
    }

    private function buildFingerprint(): string
    {
        $fields = [
            (string) $this->context->organizationId,
            (string) $this->context->projectId,
            (string) $this->context->sessionId,
            (string) ($this->context->documentId ?? ''),
            (string) ($this->context->pageId ?? ''),
            (string) ($this->context->unitId ?? ''),
            $this->context->stage->value,
            $this->context->operation,
            (string) ($this->context->provider ?? ''),
            (string) ($this->context->model ?? ''),
            $this->category->value,
            $this->code,
        ];

        return 'sha256:'.hash('sha256', implode("\0", $fields));
    }
}
