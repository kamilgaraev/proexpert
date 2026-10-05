<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use InvalidArgumentException;
use LogicException;

final readonly class SyntheticFixture
{
    private function __construct()
    {
    }

    public static function named(string $name): self
    {
        if ($name !== 'concrete-quantity-v1') {
            throw new InvalidArgumentException('unknown_synthetic_fixture');
        }

        return new self();
    }

    public function question(): string
    {
        return 'Покажи объём бетона.';
    }

    public function text(): string
    {
        return 'Материал: бетон; объём: 12.5 м³.';
    }

    public function matches(PrivateProjection $projection): bool
    {
        if ($projection->input()->question() !== $this->question()
            || $projection->context()->policyVersion() !== 'most-ai-v1-purpose-policy/0.2-product-approved-20261005'
            || count($projection->sources()) !== 1) {
            return false;
        }

        $source = $projection->sources()[0];

        if (!$source instanceof PrivateSourceVersion || !$source->hasFixtureProvenance()) {
            return false;
        }

        $expected = ['material' => 'бетон', 'quantity' => '12.5', 'unit' => 'm3', 'selected_text' => $this->text()];
        $actual = [];

        foreach ($projection->fields() as $field) {
            if (!$field instanceof PrivateField) {
                return false;
            }

            $category = $field->effectiveCategory();

            if ($category === PrivacyCategory::Unknown) {
                return false;
            }

            if ($category === PrivacyCategory::Personal || $category === PrivacyCategory::LocalOnly) {
                continue;
            }

            $actual[$field->name()] = $field->value();
        }

        return $actual == $expected;
    }

    public function __serialize(): array
    {
        throw new LogicException('synthetic_fixture_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('synthetic_fixture_deserialization_forbidden');
    }

    private function __clone(): void
    {
    }
}
