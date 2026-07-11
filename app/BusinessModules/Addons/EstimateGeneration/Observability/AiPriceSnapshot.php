<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AiPriceSnapshot
{
    private const RATE_KEYS = [
        'input_per_million', 'cached_input_per_million', 'output_per_million',
        'reasoning_per_million', 'image_unit', 'page_unit',
    ];

    /** @param array<string, string> $rates */
    private function __construct(
        public bool $available,
        public array $rates,
        public ?string $reasoningMode,
        public ?string $currency,
        public ?string $source,
        public ?string $version,
        public ?string $effectiveAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if ($data === []) {
            return new self(false, [], null, null, null, null, null);
        }
        $allowed = [...self::RATE_KEYS, 'reasoning_mode', 'currency', 'source', 'version', 'effective_at'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidArgumentException('Price snapshot contains unsupported fields.');
        }
        foreach (['input_per_million', 'cached_input_per_million', 'output_per_million', 'currency', 'source', 'version', 'effective_at'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || trim($data[$key]) === '') {
                throw new InvalidArgumentException('Price snapshot is incomplete.');
            }
        }
        $rates = [];
        foreach (self::RATE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if (! is_string($data[$key]) || preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,8})?$/', $data[$key]) !== 1) {
                throw new InvalidArgumentException('Price rates must be bounded decimal strings.');
            }
            $rates[$key] = $data[$key];
        }
        $mode = (string) ($data['reasoning_mode'] ?? 'excluded_from_output');
        if (! in_array($mode, ['included_in_output', 'excluded_from_output'], true)
            || preg_match('/^[A-Z]{3}$/', $data['currency']) !== 1
            || ! in_array($data['source'], ['config', 'provider', 'contract', 'fixture'], true)
            || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $data['version']) !== 1) {
            throw new InvalidArgumentException('Invalid price snapshot dimensions.');
        }
        $effectiveAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $data['effective_at']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (! $effectiveAt instanceof DateTimeImmutable
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $effectiveAt->format('Y-m-d\TH:i:sP') !== $data['effective_at']) {
            throw new InvalidArgumentException('Price effective_at must be exact RFC3339 seconds.');
        }

        return new self(true, $rates, $mode, $data['currency'], $data['source'], $data['version'], $data['effective_at']);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        if (! $this->available) {
            return [];
        }

        return [...$this->rates, 'reasoning_mode' => (string) $this->reasoningMode, 'currency' => (string) $this->currency,
            'source' => (string) $this->source, 'version' => (string) $this->version, 'effective_at' => (string) $this->effectiveAt];
    }
}
