<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\DTO;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use BackedEnum;
use DateTimeInterface;

final readonly class ProjectCommandCenterData
{
    private function __construct(
        private array $project,
        private array $period,
        private DateTimeInterface $generatedAt,
        private array $problems,
        private array $finance,
        private array $delivery,
        private array $analytics,
    ) {
    }

    public static function empty(
        Project $project,
        ProjectContext $projectContext,
        string $period,
        ?string $dateFrom,
        ?string $dateTo,
        DateTimeInterface $generatedAt,
    ): self {
        return new self(
            project: [
                'id' => $project->getKey(),
                'name' => $project->name,
                'status' => self::normalizeEnum($project->status),
                'start_date' => self::normalizeDate($project->start_date),
                'end_date' => self::normalizeDate($project->end_date),
            ],
            period: [
                'preset' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            generatedAt: $generatedAt,
            problems: [],
            finance: self::canViewFinances($projectContext) ? [] : ['available' => false],
            delivery: [],
            analytics: [],
        );
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project,
            'period' => $this->period,
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'problems' => $this->problems,
            'finance' => $this->finance,
            'delivery' => $this->delivery,
            'analytics' => $this->analytics,
        ];
    }

    private static function normalizeDate(mixed $date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return is_string($date) ? $date : null;
    }

    private static function normalizeEnum(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private static function canViewFinances(ProjectContext $projectContext): bool
    {
        return $projectContext->roleConfig->canViewFinances
            || $projectContext->hasPermission('view_own_finances');
    }
}
