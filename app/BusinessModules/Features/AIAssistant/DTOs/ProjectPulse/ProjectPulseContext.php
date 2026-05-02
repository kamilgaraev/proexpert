<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse;

use Carbon\CarbonImmutable;

final readonly class ProjectPulseContext
{
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public string $period,
        public CarbonImmutable $date,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $useAi,
        public ?int $userId,
    ) {
    }

    public static function fromValidated(array $data, int $organizationId, ?int $userId): self
    {
        $period = (string) ($data['period'] ?? 'today');
        $date = CarbonImmutable::parse($data['date'] ?? now()->toDateString())->startOfDay();

        [$from, $to] = match ($period) {
            'yesterday' => [$date->subDay()->startOfDay(), $date->subDay()->endOfDay()],
            'week' => [$date->startOfWeek(), $date->endOfDay()],
            default => [$date->startOfDay(), $date->endOfDay()],
        };

        return new self(
            organizationId: $organizationId,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            period: $period,
            date: $date,
            from: $from,
            to: $to,
            useAi: (bool) ($data['use_ai'] ?? true),
            userId: $userId,
        );
    }
}
