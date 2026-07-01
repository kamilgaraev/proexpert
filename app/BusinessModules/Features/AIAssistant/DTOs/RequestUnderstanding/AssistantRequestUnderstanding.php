<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding;

final readonly class AssistantRequestUnderstanding
{
    public function __construct(
        public string $primaryIntent,
        public string $outputFormat,
        public string $actionPolicy,
        public array $constraints,
        public array $requestedEntities,
        public float $confidence,
        public array $evidence,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            self::stringValue($payload['primary_intent'] ?? null, 'unknown'),
            self::stringValue($payload['output_format'] ?? null, 'any'),
            self::stringValue($payload['action_policy'] ?? null, 'read_only'),
            self::stringList($payload['constraints'] ?? []),
            self::stringList($payload['requested_entities'] ?? []),
            self::floatValue($payload['confidence'] ?? null, 0.5),
            is_array($payload['evidence'] ?? null) ? array_values($payload['evidence']) : [],
        );
    }

    public function hasConstraint(string $constraint): bool
    {
        return in_array($constraint, $this->constraints, true);
    }

    public function blocksFileGeneration(): bool
    {
        foreach (['no_file', 'no_pdf', 'no_report', 'text_only', 'json_only', 'no_actions'] as $constraint) {
            if ($this->hasConstraint($constraint)) {
                return true;
            }
        }

        return false;
    }

    public function blocksActions(): bool
    {
        return $this->actionPolicy === 'read_only'
            || $this->hasConstraint('no_actions')
            || $this->hasConstraint('text_only')
            || $this->hasConstraint('json_only');
    }

    public function blocksNavigation(): bool
    {
        return $this->actionPolicy !== 'allow_navigation'
            || $this->hasConstraint('no_navigation')
            || $this->hasConstraint('no_actions')
            || $this->hasConstraint('json_only');
    }

    public function toArray(): array
    {
        return [
            'primary_intent' => $this->primaryIntent,
            'output_format' => $this->outputFormat,
            'action_policy' => $this->actionPolicy,
            'constraints' => array_values($this->constraints),
            'requested_entities' => array_values($this->requestedEntities),
            'confidence' => $this->confidence,
            'evidence' => array_values($this->evidence),
        ];
    }

    private static function stringValue(mixed $value, string $default): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private static function floatValue(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value
        ), static fn (string $item): bool => $item !== '')));
    }
}
