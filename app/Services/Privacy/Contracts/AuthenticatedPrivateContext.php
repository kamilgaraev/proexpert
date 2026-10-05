<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final readonly class AuthenticatedPrivateContext implements JsonSerializable
{
    public function __construct(
        private int $actorId,
        private int $organizationId,
        private ?int $projectId,
        private string $policyVersion,
        private string $aclEpoch,
        private string $purpose = 'assistant_chat',
    ) {
        foreach ([$actorId, $organizationId, $projectId] as $id) {
            if ($id !== null && ($id < 1 || $id > 9007199254740991)) {
                throw new InvalidArgumentException('invalid_private_context');
            }
        }

        foreach ([$policyVersion, $aclEpoch] as $version) {
            if (preg_match('~^[A-Za-z0-9._/-]{1,128}$~D', $version) !== 1) {
                throw new InvalidArgumentException('invalid_context_version');
            }
        }
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    public function projectId(): ?int
    {
        return $this->projectId;
    }

    public function policyVersion(): string
    {
        return $this->policyVersion;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function sameSnapshot(self $other): bool
    {
        return $this->actorId === $other->actorId
            && $this->organizationId === $other->organizationId
            && $this->projectId === $other->projectId
            && $this->policyVersion === $other->policyVersion
            && $this->aclEpoch === $other->aclEpoch
            && $this->purpose === $other->purpose;
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
        return ['schemaVersion' => 'authenticated-private-context/1'];
    }
}
