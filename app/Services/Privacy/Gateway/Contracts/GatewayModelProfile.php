<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway\Contracts;

use LogicException;

final readonly class GatewayModelProfile
{
    public const KEYS = [
        'profileRef', 'qualification', 'adapterRevision', 'apiMethod', 'endpoint',
        'modelId', 'modelRevision', 'tokenizerId', 'tokenizerRevision',
        'mappingEvidenceRef', 'capabilityEvidenceRef', 'capacityEvidenceRef',
        'contextWindow', 'maxOutputTokens', 'answerReserve', 'toolReserve',
    ];

    private function __construct(private array $value) {}

    public static function unqualified(): self
    {
        $value = array_fill_keys(self::KEYS, null);
        $value['qualification'] = 'unqualified';

        return new self($value);
    }

    public static function fromArray(mixed $value): self
    {
        if (! GatewayModelRequest::hasExactKeys($value, self::KEYS)
            || ! in_array($value['qualification'], ['local-stub', 'actual'], true)) {
            throw new LogicException('model_profile_unqualified');
        }
        foreach (['profileRef', 'mappingEvidenceRef', 'capabilityEvidenceRef', 'capacityEvidenceRef'] as $key) {
            if (! GatewayModelRequest::isReference($value[$key])) {
                throw new LogicException('model_profile_unqualified');
            }
        }
        foreach (['adapterRevision', 'modelId', 'modelRevision', 'tokenizerId', 'tokenizerRevision'] as $key) {
            if (! is_string($value[$key]) || preg_match('/\A[A-Za-z0-9_.:\/-]{1,128}\z/D', $value[$key]) !== 1) {
                throw new LogicException('model_profile_unqualified');
            }
        }
        foreach (['contextWindow', 'maxOutputTokens', 'answerReserve', 'toolReserve'] as $key) {
            if (! is_int($value[$key]) || $value[$key] < 1 || $value[$key] > 10000000) {
                throw new LogicException('model_profile_unqualified');
            }
        }
        if ($value['maxOutputTokens'] < $value['answerReserve'] + $value['toolReserve']
            || $value['maxOutputTokens'] >= $value['contextWindow']) {
            throw new LogicException('model_profile_unqualified');
        }
        if ($value['qualification'] === 'actual') {
            if ($value['apiMethod'] !== 'chat_completions'
                || $value['endpoint'] !== 'https://api.timeweb.ai/v1/chat/completions') {
                throw new LogicException('model_profile_unqualified');
            }
        } elseif ($value['apiMethod'] !== 'local_action' || $value['endpoint'] !== 'local://public-core-stub'
            || $value['modelId'] !== 'local-action-stub') {
            throw new LogicException('model_profile_unqualified');
        }

        return new self($value);
    }

    public function values(): array
    {
        return $this->value;
    }

    public function fingerprint(): string
    {
        return hash('sha256', GatewayModelRequest::canonicalJson($this->value));
    }

    public function isQualified(): bool
    {
        return in_array($this->value['qualification'], ['local-stub', 'actual'], true);
    }
}
