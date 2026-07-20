<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Sources;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use InvalidArgumentException;

final readonly class LegalDocumentSourceCreateIdentity
{
    private function __construct(
        public int $organizationId,
        public ?int $actorId,
        public string $sourceType,
        public string $sourceId,
        public string $idempotencyKey,
    ) {}

    public static function fromInput(int $organizationId, ?int $actorId, array $data): ?self
    {
        $sourceType = $data['source_type'] ?? null;
        $sourceId = $data['source_id'] ?? null;
        $idempotencyKey = $data['source_idempotency_key'] ?? null;
        $present = [
            self::isPresent($sourceType),
            self::isPresent($sourceId),
            self::isPresent($idempotencyKey),
        ];

        if (! in_array(true, $present, true)) {
            return null;
        }

        if (
            in_array(false, $present, true)
            || $organizationId < 1
            || ($actorId !== null && $actorId < 1)
            || ! is_string($sourceType)
            || ! is_string($idempotencyKey)
        ) {
            throw new InvalidArgumentException('Incomplete legal document source identity.');
        }

        $sourceType = trim($sourceType);
        $idempotencyKey = trim($idempotencyKey);
        $normalizedSourceId = self::normalizeSourceId($sourceId);
        if (
            LegalDocumentSourceType::tryFrom($sourceType) === null
            || $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 191
            || $normalizedSourceId === null
        ) {
            throw new InvalidArgumentException('Invalid legal document source identity.');
        }

        return new self(
            organizationId: $organizationId,
            actorId: $actorId,
            sourceType: $sourceType,
            sourceId: $normalizedSourceId,
            idempotencyKey: $idempotencyKey,
        );
    }

    public function normalizeInput(array $data): array
    {
        $data['source_type'] = $this->sourceType;
        $data['source_id'] = $this->sourceId;
        $data['source_idempotency_key'] = $this->idempotencyKey;

        return $data;
    }

    public function matches(LegalArchiveDocument $document): bool
    {
        return ($document->getAttributes()[$document->getDeletedAtColumn()] ?? null) === null
            && (int) $document->organization_id === $this->organizationId
            && $this->nullableInt($document->created_by_user_id) === $this->actorId
            && (string) $document->source_type === $this->sourceType
            && (string) $document->source_id === $this->sourceId
            && (string) $document->source_idempotency_key === $this->idempotencyKey;
    }

    public function sourceEventId(): string
    {
        $actor = $this->actorId === null ? 'system' : (string) $this->actorId;

        return "create:org-{$this->organizationId}:actor-{$actor}:{$this->sourceType}:{$this->sourceId}:{$this->idempotencyKey}";
    }

    private static function isPresent(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private static function normalizeSourceId(mixed $sourceId): ?string
    {
        if (is_int($sourceId)) {
            return $sourceId > 0 ? (string) $sourceId : null;
        }
        if (! is_string($sourceId)) {
            return null;
        }
        $sourceId = trim($sourceId);
        if ($sourceId === '' || ! ctype_digit($sourceId)) {
            return null;
        }
        $normalized = ltrim($sourceId, '0');

        return $normalized === '' ? null : $normalized;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
