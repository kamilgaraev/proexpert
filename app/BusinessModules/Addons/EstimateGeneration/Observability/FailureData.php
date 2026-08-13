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
        if ((new SensitiveDiagnosticSanitizer)->sanitize($safeContext) !== $safeContext
            || strlen(json_encode($safeContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) > 2048) {
            throw new InvalidArgumentException('Failure safe context violates the closed diagnostic contract.');
        }

        $this->fingerprint = $this->buildFingerprint();
    }

    private function buildFingerprint(): string
    {
        $diagnosticFingerprint = $this->safeContext['diagnostic_fingerprint'] ?? null;
        $fields = [
            (string) $this->context->organizationId,
            (string) $this->context->projectId,
            (string) $this->context->sessionId,
            (string) ($this->context->documentId ?? ''),
            $this->context->stage->value,
            $this->context->operation,
            (string) ($this->context->provider ?? ''),
            (string) ($this->context->model ?? ''),
            $this->category->value,
            $this->code,
            is_string($diagnosticFingerprint) ? $diagnosticFingerprint : '',
        ];

        if (! is_string($diagnosticFingerprint)) {
            $fields[] = (string) ($this->context->pageId ?? '');
            $fields[] = (string) ($this->context->unitId ?? '');
        }

        return 'sha256:'.hash('sha256', implode("\0", $fields));
    }
}
