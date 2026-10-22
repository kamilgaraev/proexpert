<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantOrganizationQualityControlIntent;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectSeverityEnum;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationQualityDefectControlReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_quality_defect_control_projection';

    private const EXAMPLE_LIMIT = 50;

    public static function hasIntent(string $query): bool
    {
        return self::matches($query);
    }

    public static function matches(string $query): bool
    {
        return AssistantOrganizationQualityControlIntent::matches($query);
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $read = fn (): array => parent::read($actor, $organizationId, $arguments);

        return app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)->withOperationBudget($read, 30_000, 'pgsql') : $read();
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canReadDomain($actor, $organizationId, 'quality')) {
            throw new AuthorizationException;
        }
        $query = $this->access->entityQuery($actor, $organizationId, 'quality_defect');
        if ($query === null) {
            throw new AuthorizationException;
        }
        $cutoff = now('Europe/Moscow')->toDateString();
        $query->select(['quality_defects.id', 'quality_defects.project_id', 'quality_defects.title',
            'quality_defects.status', 'quality_defects.severity', 'quality_defects.due_date']);
        $projects = $this->access->entityQuery($actor, $organizationId, 'project');
        if ($projects === null) {
            $query->selectRaw('NULL::text AS project_name');
        } else {
            $query->addSelect(['project_name' => $projects->select('projects.name')
                ->whereColumn('projects.id', 'quality_defects.project_id')->limit(1)]);
        }
        $records = $query->whereNotIn('quality_defects.status', ['draft', 'resolved', 'cancelled'])
            ->where('quality_defects.due_date', '<', $cutoff)
            ->selectRaw("COUNT(*) OVER () AS matched_count, COUNT(*) FILTER (WHERE quality_defects.severity = 'critical') OVER () AS critical_count")
            ->orderByRaw("CASE WHEN quality_defects.severity = 'critical' THEN 0 ELSE 1 END")
            ->orderBy('quality_defects.due_date')->orderBy('quality_defects.id')
            ->limit(self::EXAMPLE_LIMIT)->toBase()->get();
        $total = (int) ($records->first()->matched_count ?? 0);
        $critical = (int) ($records->first()->critical_count ?? 0);
        $rows = $records->map(static fn (object $row): array => ['id' => (int) $row->id,
            'project_id' => $row->project_id === null ? null : (int) $row->project_id,
            'project_name' => $row->project_name === null ? null : (string) $row->project_name,
            'title' => (string) $row->title, 'status' => (string) $row->status,
            'severity' => (string) $row->severity, 'due_date' => (string) $row->due_date])->all();

        return ['cutoff_date' => $cutoff, 'timezone' => 'Europe/Moscow', 'total_count' => $total,
            'critical_count' => $critical, 'shown_count' => count($rows), 'truncated' => $total > count($rows), 'rows' => $rows];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_quality_control.scope', ['date' => $data['cutoff_date']]),
            trans_message('ai_assistant_quality_control.criteria'),
            trans_message('ai_assistant_quality_control.totals', ['total' => $data['total_count'], 'critical' => $data['critical_count']])];
        if ($data['rows'] === []) {
            $lines[] = trans_message('ai_assistant_quality_control.empty');

            return implode("\n\n", $lines);
        }
        foreach (['critical', 'other'] as $group) {
            $rows = array_filter($data['rows'], static fn (array $row): bool => ($row['severity'] === 'critical') === ($group === 'critical'));
            if ($rows === []) {
                continue;
            }
            $lines[] = trans_message('ai_assistant_quality_control.'.$group);
            $table = [trans_message('ai_assistant_quality_control.columns'), '| --- | --- | --- | --- | --- | --- |'];
            foreach ($rows as $row) {
                $project = $row['project_id'] === null ? trans_message('ai_assistant_quality_control.no_project')
                    : ($row['project_name'] ?? trans_message('ai_assistant_quality_control.project_unavailable'));
                $cells = [$project, $row['title'], QualityDefectStatusEnum::tryFrom($row['status'])?->label() ?? $row['status'],
                    QualityDefectSeverityEnum::tryFrom($row['severity'])?->label() ?? $row['severity'], $row['due_date']];
                $cells = array_map(static fn (string $value): string => str_replace('|', '\\|', self::text($value)), $cells);
                $table[] = '| ['.$row['id'].'](/quality-control/defects/'.$row['id'].') | '.implode(' | ', $cells).' |';
            }
            $lines[] = implode("\n", $table);
        }
        if ($data['truncated']) {
            $lines[] = trans_message('ai_assistant_quality_control.truncated', ['shown' => $data['shown_count'], 'total' => $data['total_count']]);
        }

        return implode("\n\n", $lines);
    }
}
