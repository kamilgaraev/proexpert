<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\Services\LegalArchive\CanonicalJson;
use UnexpectedValueException;

final readonly class VersionInput
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public ?string $versionNumber = null,
        public ?string $versionLabel = null,
        public ?int $uploadedByUserId = null,
        public ?array $metadata = null,
        public bool $makeCurrent = true,
        public ?int $expectedDocumentLockVersion = null,
    ) {}

    public function semanticFingerprint(): string
    {
        return CanonicalJson::fingerprint($this->semanticPayload());
    }

    public static function fromCreateData(?int $actorId, array $data): self
    {
        return new self(
            versionNumber: isset($data['version_number']) ? (string) $data['version_number'] : '1.0',
            versionLabel: isset($data['version_label']) ? (string) $data['version_label'] : null,
            uploadedByUserId: $actorId,
            metadata: is_array($data['version_metadata'] ?? null) ? $data['version_metadata'] : null,
        );
    }

    public function semanticPayload(): array
    {
        return [
            'version_number' => $this->versionNumber,
            'version_label' => $this->versionLabel,
            'uploaded_by_user_id' => $this->uploadedByUserId,
            'metadata' => $this->metadata,
            'make_current' => $this->makeCurrent,
            'expected_document_lock_version' => $this->expectedDocumentLockVersion,
        ];
    }

    public static function fromOperation(object $operation): self
    {
        $metadata = $operation->version_metadata ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        }

        return new self(
            versionNumber: isset($operation->requested_version_number)
                ? (string) $operation->requested_version_number
                : null,
            versionLabel: isset($operation->version_label) ? (string) $operation->version_label : null,
            uploadedByUserId: isset($operation->uploaded_by_user_id) ? (int) $operation->uploaded_by_user_id : null,
            metadata: is_array($metadata) ? $metadata : null,
            makeCurrent: self::normalizeBoolean($operation->make_current ?? null),
        );
    }

    private static function normalizeBoolean(mixed $value): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 't', $value === 'true' => true,
            $value === false, $value === 0, $value === '0', $value === 'f', $value === 'false' => false,
            default => throw new UnexpectedValueException('legal_document_version_operation_make_current_invalid'),
        };
    }
}
