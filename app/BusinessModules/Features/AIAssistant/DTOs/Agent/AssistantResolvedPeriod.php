<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\Agent;

final readonly class AssistantResolvedPeriod
{
    public function __construct(
        public ?string $dateFrom,
        public ?string $dateTo,
        public string $label,
        public string $sourceText
    ) {}

    /**
     * @return array{date_from: string|null, date_to: string|null, label: string, source_text: string}
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'label' => $this->label,
            'source_text' => $this->sourceText,
        ];
    }
}
