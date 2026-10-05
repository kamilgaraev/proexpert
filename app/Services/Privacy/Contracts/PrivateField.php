<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final readonly class PrivateField implements JsonSerializable
{
    public function __construct(private string $name, private PrivacyCategory $category, private string $value)
    {
        if ($name === '' || strlen($name) > 128 || strlen($value) > 8000
            || preg_match('//u', $name . $value) !== 1) {
            throw new InvalidArgumentException('invalid_private_field');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function effectiveCategory(): PrivacyCategory
    {
        $known = PrivacyCategory::forField($this->name);

        if ($known === PrivacyCategory::Personal || $known === PrivacyCategory::LocalOnly) {
            return $known;
        }

        return $known === $this->category ? $known : PrivacyCategory::Unknown;
    }

    public function sameValue(self $other): bool
    {
        return $this->name === $other->name
            && $this->category === $other->category
            && $this->value === $other->value;
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
        return ['schemaVersion' => 'private-field/1'];
    }
}
