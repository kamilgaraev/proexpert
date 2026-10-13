<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;

final readonly class AssistantProjectDeliveriesReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_delivery_queue_projection';

    public static function matches(string $query): bool
    {
        return preg_match('/(?:материал[а-яё]*.*(?:жд[её]м|ожида|достав)|(?:жд[её]м|ожида)[\s\S]*материал|поставк[а-яё]*.*просроч|просроч[а-яё]*.*(?:поставк|достав))/iu', $query) === 1
            && preg_match('/создай|удали|измени|прими|оплат|сч[её]т|смет|бюджет|стоим|сумм|сотрудник|договор|задач/iu', $query) !== 1;
    }

    protected function domain(): string
    {
        return 'warehouse';
    }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'project_material_delivery');
        if ($query === null) {
            return ['available' => false];
        }
        $open = $query->whereNotIn('project_material_deliveries.status', ['accepted', 'cancelled']);
        $total = (clone $open)->count();
        $overdue = (clone $open)->whereDate('planned_delivery_date', '<', today()->toDateString())->count();
        $undated = (clone $open)->whereNull('planned_delivery_date')->count();
        $rows = $open->orderByRaw('planned_delivery_date IS NULL')->orderBy('planned_delivery_date')->orderBy('id')->limit(20)
            ->select(['id', 'material_id', 'status', 'source_type', 'planned_delivery_date'])->get();
        $materials = $this->access->entityQuery($actor, $organizationId, 'material')?->whereKey($rows->pluck('material_id')->unique()->all())
            ->select(['id', 'name'])->get()->keyBy('id');

        return ['available' => true, 'total' => $total, 'overdue' => $overdue, 'undated' => $undated,
            'examples' => $rows->map(static fn ($row): array => ['id' => (int) $row->id, 'material' => $materials?->get($row->material_id)?->name,
                'status' => $row->getRawOriginal('status'), 'source' => $row->getRawOriginal('source_type'), 'date' => $row->planned_delivery_date?->toDateString()])->all()];
    }

    protected function format(array $data): string
    {
        if (! $data['available']) {
            return trans_message('ai_assistant_operational.deliveries_unavailable');
        }
        $lines = [trans_message('ai_assistant_operational.deliveries_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.deliveries_counts', ['total' => $data['total'], 'overdue' => $data['overdue'], 'undated' => $data['undated']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.($row['material'] === null ? trans_message('ai_assistant_operational.deliveries_material_unknown') : self::text($row['material'])).'; '
                .trans_message('ai_assistant_operational.delivery_status_'.$row['status']).'; '.trans_message('ai_assistant_operational.delivery_source_'.$row['source']).'; '
                .trans_message('ai_assistant_operational.orders_date', ['date' => $row['date'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        $lines[] = trans_message('ai_assistant_operational.deliveries_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');

        return implode("\n", $lines);
    }
}
