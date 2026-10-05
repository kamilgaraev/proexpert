<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final readonly class PrivateSourceVersion implements JsonSerializable
{
    public function __construct(
        private int $sourceId,
        private int $organizationId,
        private ?int $projectId,
        private string $revision,
        private string $generation,
        private string $aclEpoch,
        private string $deletionState = 'present',
        private string $freshness = 'fresh',
        private string $sourceClass = 'unknown',
    ) {
        foreach ([$sourceId, $organizationId, $projectId] as $id) {
            if ($id !== null && ($id < 1 || $id > 9007199254740991)) {
                throw new InvalidArgumentException('invalid_source_identity');
            }
        }

        foreach ([$revision, $generation, $aclEpoch] as $version) {
            if (preg_match('~^[A-Za-z0-9._/-]{1,128}$~D', $version) !== 1) {
                throw new InvalidArgumentException('invalid_source_version');
            }
        }

        if (!in_array($deletionState, ['present', 'deleted'], true)
            || !in_array($freshness, ['fresh', 'stale', 'unknown'], true)
            || !in_array($sourceClass, ['public', 'synthetic', 'private', 'unknown'], true)) {
            throw new InvalidArgumentException('invalid_source_state');
        }
    }

    public function sourceId(): int
    {
        return $this->sourceId;
    }

    public function isUsableBy(AuthenticatedPrivateContext $context): bool
    {
        return $this->organizationId === $context->organizationId()
            && ($context->projectId() === null || $this->projectId === $context->projectId())
            && $this->deletionState === 'present'
            && $this->freshness === 'fresh'
            && $this->sourceClass !== 'unknown';
    }

    public function hasFixtureProvenance(): bool
    {
        return $this->sourceClass === 'synthetic'
            && $this->revision === 'fixture/1'
            && $this->generation === 'synthetic-concrete/1';
    }

    public function sameSnapshot(self $other): bool
    {
        return $this->sourceId === $other->sourceId
            && $this->organizationId === $other->organizationId
            && $this->projectId === $other->projectId
            && $this->revision === $other->revision
            && $this->generation === $other->generation
            && $this->aclEpoch === $other->aclEpoch
            && $this->deletionState === $other->deletionState
            && $this->freshness === $other->freshness
            && $this->sourceClass === $other->sourceClass;
    }

    public function jsonSerialize(): array
    {
        throw new LogicException('private_serialization_forbidden');
    }

    public function __serialize(): array
    {
        throw new LogicException('private_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('private_deserialization_forbidden');
    }

    public function __debugInfo(): array
    {
        return ['schemaVersion' => 'private-source-version/1'];
    }
}
