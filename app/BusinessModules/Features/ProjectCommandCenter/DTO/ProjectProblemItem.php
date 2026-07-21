<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\DTO;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class ProjectProblemItem
{
    private const ACTION_ROUTES = [
        'safety' => '/safety-management',
        'schedule' => '/projects/{project_id}/schedules',
        'completed_work' => '/workflow/completed-works',
    ];

    private const ACTION_MODULES = [
        'safety',
        'schedule',
        'completed_work',
        'materials',
        'quality',
    ];

    /**
     * @param list<string> $impactTypes
     */
    public function __construct(
        public string $id,
        public string $severity,
        public string $module,
        public string $title,
        public string $description,
        public array $impactTypes,
        public ?float $amount,
        public ?DateTimeInterface $dueAt,
        public DateTimeInterface $detectedAt,
        public string $actionModule,
    ) {
        if (! in_array($actionModule, self::ACTION_MODULES, true)) {
            throw new InvalidArgumentException('Неизвестный модуль действия проблемы.');
        }
    }

    public function isOverdue(DateTimeInterface $now): bool
    {
        return $this->dueAt !== null && $this->dueAt < $now;
    }

    public static function fromWorkflowSurface(
        string $id,
        string $module,
        string $title,
        array $surface,
        array $impactTypes,
        ?float $amount,
        ?DateTimeInterface $dueAt,
        DateTimeInterface $detectedAt,
        string $actionModule,
    ): ?self {
        $flag = $surface['problem_flags'][0] ?? null;
        if (! is_array($flag)) {
            return null;
        }

        $severity = match ($flag['severity'] ?? 'warning') {
            'critical', 'blocker' => 'critical',
            'warning' => 'risk',
            default => 'attention',
        };
        $description = (string) ($flag['message'] ?? $surface['workflow_summary']['stage_label'] ?? $surface['workflow_summary']['status_label'] ?? '');

        return new self(
            id: $id,
            severity: $severity,
            module: $module,
            title: $title,
            description: $description,
            impactTypes: $impactTypes,
            amount: $amount,
            dueAt: $dueAt,
            detectedAt: $detectedAt,
            actionModule: $actionModule,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(int $projectId): array
    {
        $actionRoute = self::ACTION_ROUTES[$this->actionModule] ?? null;

        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'module' => $this->module,
            'title' => $this->title,
            'description' => $this->description,
            'impact_types' => $this->impactTypes,
            'amount' => $this->amount,
            'due_at' => $this->dueAt?->format(DATE_ATOM),
            'detected_at' => $this->detectedAt->format(DATE_ATOM),
            'action' => $actionRoute === null ? null : [
                'route' => str_replace('{project_id}', (string) $projectId, $actionRoute),
                'query' => ['project_id' => $projectId],
            ],
        ];
    }
}
