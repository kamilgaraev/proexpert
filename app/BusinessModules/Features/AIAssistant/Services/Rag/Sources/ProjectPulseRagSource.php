<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use DateTimeInterface;
use Throwable;

final class ProjectPulseRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'project_pulse';
    }

    public function enabled(): bool
    {
        try {
            return (bool) config('ai-assistant.rag.enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = ProjectPulseReport::query()
            ->with('project')
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderByDesc('report_date')
            ->orderByDesc('id');

        foreach ($query->cursor() as $report) {
            yield $this->chunk($report);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if (! in_array($entityType, ['project_pulse_report', 'project_pulse'], true)) {
            return [];
        }

        $report = ProjectPulseReport::query()
            ->with('project')
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $report instanceof ProjectPulseReport ? [$this->chunk($report)] : [];
    }

    private function chunk(ProjectPulseReport $report): RagChunkData
    {
        $content = $this->lines([
            'Пульс проекта: '.$this->stringValue($report->project?->name),
            'Дата отчета: '.$this->dateValue($report->report_date),
            'Период: '.$this->dateValue($report->period_from).' - '.$this->dateValue($report->period_to),
            'Статус: '.$this->stringValue($report->status),
            'AI-статус: '.$this->stringValue($report->ai_status),
            'Сводка: '.$this->jsonText($report->summary),
            'Срочные действия: '.$this->jsonText($report->urgent_actions),
            'Группы риска: '.$this->jsonText($report->risk_groups),
            'Рекомендации: '.$this->jsonText($report->recommendations),
        ]);

        return new RagChunkData(
            organizationId: (int) $report->organization_id,
            projectId: $report->project_id !== null ? (int) $report->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'project_pulse_report',
            entityId: (int) $report->id,
            title: 'Пульс проекта: '.$this->dateValue($report->report_date),
            content: $content,
            metadata: [
                'status' => $report->status,
                'ai_status' => $report->ai_status,
                'period_preset' => $report->period_preset,
                'scope_type' => $report->scope_type,
            ],
            updatedAt: $report->updated_at
        );
    }

    private function lines(array $lines): string
    {
        return implode("\n", array_filter($lines, static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function jsonText(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? mb_substr($encoded, 0, 700) : '';
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
