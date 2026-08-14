<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

final readonly class GeometryExpertResult
{
    /** @param list<array<string,mixed>> $quantities @param list<array<string,mixed>> $conflicts @param list<array<string,mixed>> $questions @param list<array<string,mixed>> $quarantinedIntents */
    public function __construct(
        public array $quantities,
        public array $conflicts,
        public array $questions,
        public array $skippedSheets,
        public array $quarantinedIntents = [],
        public ?string $physicalAttemptId = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'quantities' => $this->quantities,
            'conflicts' => $this->conflicts,
            'questions' => $this->questions,
            'skipped_sheets' => $this->skippedSheets,
            'quarantined_intents' => $this->quarantinedIntents,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload, ?string $physicalAttemptId = null): self
    {
        return new self(
            is_array($payload['quantities'] ?? null) ? array_values($payload['quantities']) : [],
            is_array($payload['conflicts'] ?? null) ? array_values($payload['conflicts']) : [],
            is_array($payload['questions'] ?? null) ? array_values($payload['questions']) : [],
            is_array($payload['skipped_sheets'] ?? null) ? array_values($payload['skipped_sheets']) : [],
            is_array($payload['quarantined_intents'] ?? null) ? array_values($payload['quarantined_intents']) : [],
            $physicalAttemptId,
        );
    }
}
