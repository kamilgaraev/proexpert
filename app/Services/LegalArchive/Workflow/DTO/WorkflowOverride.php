<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow\DTO;

final readonly class WorkflowOverride
{
    /**
     * @param  array<string, array<string, mixed>>  $stepOverrides
     * @param  list<array<string, mixed>>  $additionalSteps
     */
    public function __construct(
        public string $idempotencyKey,
        public ?int $templateId = null,
        public array $stepOverrides = [],
        public array $additionalSteps = [],
        public ?int $expectedDocumentLockVersion = null,
    ) {}

    public static function none(string $idempotencyKey): self
    {
        return new self($idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function canonicalPayload(): array
    {
        return [
            'template_id' => $this->templateId,
            'step_overrides' => $this->stepOverrides,
            'additional_steps' => $this->additionalSteps,
            'expected_document_lock_version' => $this->expectedDocumentLockVersion,
        ];
    }
}
