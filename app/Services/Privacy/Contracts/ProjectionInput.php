<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final readonly class ProjectionInput implements JsonSerializable
{
    public function __construct(
        private string $rawQuestion,
        private ?int $selectedUnitId = null,
        private ?int $selectedEntityId = null,
    ) {
        if (trim($rawQuestion) === '' || strlen($rawQuestion) > 8000 || preg_match('//u', $rawQuestion) !== 1) {
            throw new InvalidArgumentException('invalid_projection_input');
        }

        foreach ([$selectedUnitId, $selectedEntityId] as $id) {
            if ($id !== null && ($id < 1 || $id > 9007199254740991)) {
                throw new InvalidArgumentException('invalid_selection');
            }
        }
    }

    public function question(): string
    {
        return $this->rawQuestion;
    }

    public function selectedUnitId(): ?int
    {
        return $this->selectedUnitId;
    }

    public function selectedEntityId(): ?int
    {
        return $this->selectedEntityId;
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
        return ['schemaVersion' => 'private-projection-input/1'];
    }
}
