<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Context;

use Closure;
use LogicException;

final readonly class AssistantModelContextProfile
{
    private function __construct(private array $values)
    {
    }

    public static function resolve(string $profileRef, Closure $trustedSource): self
    {
        $values = $trustedSource($profileRef);
        $keys = ['profileRef', 'qualification', 'adapterRevision', 'modelId', 'modelRevision', 'tokenizerId', 'tokenizerRevision', 'contextWindow', 'maxOutputTokens', 'answerReserve', 'toolReserve'];
        if (!is_array($values) || !self::hasExactKeys($values, $keys)) {
            throw new LogicException('model_profile_unavailable');
        }
        foreach (array_slice($keys, 0, 7) as $key) {
            if (!is_string($values[$key]) || $values[$key] === '') {
                throw new LogicException('model_profile_unavailable');
            }
        }
        if ($values['profileRef'] !== $profileRef || !in_array($values['qualification'], ['offline-synthetic', 'public-gateway-actual'], true)) {
            throw new LogicException('model_profile_unqualified');
        }
        foreach (array_slice($keys, 7) as $key) {
            if (!is_int($values[$key]) || $values[$key] < 1) {
                throw new LogicException('model_profile_invalid_capacity');
            }
        }
        if ($values['answerReserve'] < $values['maxOutputTokens'] || $values['answerReserve'] >= $values['contextWindow'] || $values['toolReserve'] >= $values['contextWindow'] - $values['answerReserve']) {
            throw new LogicException('model_profile_invalid_capacity');
        }

        return new self(AssistantContextSourceBinding::detached($values));
    }

    public static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    public function identity(): array
    {
        return array_intersect_key($this->values, array_flip(['modelId', 'modelRevision', 'tokenizerId', 'tokenizerRevision']));
    }

    public function adapterRevision(): string
    {
        return $this->values['adapterRevision'];
    }

    public function inputBudget(): int
    {
        return $this->values['contextWindow'] - $this->values['answerReserve'] - $this->values['toolReserve'];
    }

    public function modelPayload(): array
    {
        return $this->identity() + array_intersect_key($this->values, array_flip(['contextWindow', 'maxOutputTokens', 'answerReserve', 'toolReserve']));
    }

    public function fingerprint(): string
    {
        return hash('sha256', AssistantContextSourceBinding::canonical($this->values));
    }

    public function __serialize(): array
    {
        throw new LogicException('context_profile_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('context_profile_deserialization_forbidden');
    }
}