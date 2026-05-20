<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class AssistantOperationalReportService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'projects_summary' => [
                'title' => 'Сводка по проектам',
                'description' => 'Общий обзор проектного портфеля, статусов и финансовых ориентиров.',
                'sections' => [
                    $this->section('projects', 'Проекты', 'projects', ['name' => 'Проект', 'status' => 'Статус', 'budget' => 'Бюджет', 'start_date' => 'Начало', 'end_date' => 'Окончание'], ['budget', 'contract_value', 'estimated_cost']),
                    $this->section('contracts', 'Договоры по проектам', 'contracts', ['number' => 'Номер', 'name' => 'Договор', 'status' => 'Статус', 'total_amount' => 'Сумма', 'created_at' => 'Создан'], ['total_amount', 'amount', 'contract_sum']),
                ],
            ],
            'procurement_requests' => [
                'title' => 'Заявки на закупку',
                'description' => 'Потребности объектов, статусы заявок и свежие позиции снабжения.',
                'sections' => [
                    $this->section('purchase_requests', 'Заявки на закупку', 'purchase_requests', ['number' => 'Номер', 'title' => 'Заявка', 'status' => 'Статус', 'priority' => 'Приоритет', 'required_date' => 'Нужно к'], ['estimated_amount', 'total_amount', 'amount']),
                    $this->section('supplier_requests', 'Запросы поставщикам', 'supplier_requests', ['number' => 'Номер', 'title' => 'Запрос', 'status' => 'Статус', 'created_at' => 'Создан'], ['estimated_amount', 'total_amount', 'amount']),
                ],
            ],
            'purchase_orders' => [
                'title' => 'Заказы поставщикам',
                'description' => 'Контроль заказов, поставок и сумм по снабжению.',
                'sections' => [
                    $this->section('purchase_orders', 'Заказы поставщикам', 'purchase_orders', ['number' => 'Номер', 'order_number' => 'Номер', 'status' => 'Статус', 'total_amount' => 'Сумма', 'created_at' => 'Создан'], ['total_amount', 'amount']),
                    $this->section('purchase_receipts', 'Приемки поставок', 'purchase_receipts', ['number' => 'Номер', 'status' => 'Статус', 'received_at' => 'Дата приемки', 'created_at' => 'Создан'], ['total_amount', 'amount']),
                ],
            ],
            'supplier_proposals' => [
                'title' => 'Предложения поставщиков',
                'description' => 'Коммерческие предложения, решения и активность поставщиков.',
                'sections' => [
                    $this->section('supplier_proposals', 'Предложения поставщиков', 'supplier_proposals', ['number' => 'Номер', 'status' => 'Статус', 'total_amount' => 'Сумма', 'created_at' => 'Создано'], ['total_amount', 'amount', 'price']),
                    $this->section('supplier_proposal_decisions', 'Решения по предложениям', 'supplier_proposal_decisions', ['status' => 'Статус', 'decision' => 'Решение', 'created_at' => 'Дата'], []),
                ],
            ],
            'site_requests' => [
                'title' => 'Заявки со стройплощадки',
                'description' => 'Оперативные обращения с объектов, приоритеты и статусы обработки.',
                'sections' => [
                    $this->section('site_requests', 'Заявки с объектов', 'site_requests', ['number' => 'Номер', 'title' => 'Заявка', 'type' => 'Тип', 'status' => 'Статус', 'priority' => 'Приоритет'], ['estimated_amount', 'amount']),
                ],
            ],
            'estimates_summary' => [
                'title' => 'Сводка по сметам',
                'description' => 'Состояние смет, версий и сумм по проектам.',
                'sections' => [
                    $this->section('estimates', 'Сметы', 'estimates', ['number' => 'Номер', 'name' => 'Смета', 'status' => 'Статус', 'total_amount' => 'Сумма', 'created_at' => 'Создана'], ['total_amount', 'amount', 'grand_total']),
                    $this->section('estimate_versions', 'Версии смет', 'estimate_versions', ['version_number' => 'Версия', 'status' => 'Статус', 'total_amount' => 'Сумма', 'created_at' => 'Создана'], ['total_amount', 'amount', 'grand_total']),
                ],
            ],
            'quality_defects' => [
                'title' => 'Дефекты качества',
                'description' => 'Замечания, статусы устранения и свежие дефекты качества.',
                'sections' => [
                    $this->section('quality_defects', 'Дефекты качества', 'quality_defects', ['number' => 'Номер', 'title' => 'Дефект', 'status' => 'Статус', 'severity' => 'Критичность', 'created_at' => 'Выявлен'], ['estimated_cost', 'cost']),
                ],
            ],
            'safety_incidents' => [
                'title' => 'Инциденты безопасности',
                'description' => 'Инциденты, нарушения и корректирующие действия по охране труда.',
                'sections' => [
                    $this->section('safety_incidents', 'Инциденты', 'safety_incidents', ['number' => 'Номер', 'title' => 'Инцидент', 'status' => 'Статус', 'severity' => 'Критичность', 'occurred_at' => 'Дата'], []),
                    $this->section('safety_violations', 'Нарушения', 'safety_violations', ['number' => 'Номер', 'title' => 'Нарушение', 'status' => 'Статус', 'created_at' => 'Дата'], []),
                    $this->section('safety_corrective_actions', 'Корректирующие действия', 'safety_corrective_actions', ['title' => 'Действие', 'status' => 'Статус', 'due_date' => 'Срок', 'created_at' => 'Создано'], []),
                ],
            ],
            'machinery_utilization' => [
                'title' => 'Работа техники',
                'description' => 'Парк техники, сменные отчеты, простои и эксплуатационная активность.',
                'sections' => [
                    $this->section('machinery_assets', 'Единицы техники', 'machinery_assets', ['name' => 'Техника', 'status' => 'Статус', 'registration_number' => 'Госномер', 'created_at' => 'Добавлена'], ['book_value', 'value']),
                    $this->section('machinery_shift_reports', 'Сменные отчеты', 'machinery_shift_reports', ['status' => 'Статус', 'worked_hours' => 'Часы', 'shift_date' => 'Дата', 'created_at' => 'Создан'], ['cost', 'amount']),
                    $this->section('machinery_downtimes', 'Простои техники', 'machinery_downtimes', ['reason' => 'Причина', 'status' => 'Статус', 'started_at' => 'Начало', 'ended_at' => 'Окончание'], ['cost', 'loss_amount']),
                ],
            ],
            'workforce_attendance' => [
                'title' => 'Посещаемость сотрудников',
                'description' => 'Явка, сканирования, отсутствия и кадровая активность.',
                'sections' => [
                    $this->section('workforce_employees', 'Сотрудники', 'workforce_employees', ['full_name' => 'Сотрудник', 'status' => 'Статус', 'personnel_number' => 'Табельный номер', 'created_at' => 'Добавлен'], []),
                    $this->section('workforce_attendance_scan_events', 'События посещаемости', 'workforce_attendance_scan_events', ['event_type' => 'Событие', 'status' => 'Статус', 'scanned_at' => 'Время', 'created_at' => 'Создано'], []),
                    $this->section('workforce_absences', 'Отсутствия', 'workforce_absences', ['reason' => 'Причина', 'status' => 'Статус', 'date_from' => 'Начало', 'date_to' => 'Окончание'], []),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(string $reportType, Organization $organization, ?User $user, array $filters = []): array
    {
        $definition = $this->definitions()[$reportType] ?? null;
        if (! is_array($definition)) {
            throw new InvalidArgumentException('Unknown operational report type.');
        }

        $dateFrom = $this->dateString($filters['date_from'] ?? null);
        $dateTo = $this->dateString($filters['date_to'] ?? null);
        $projectId = isset($filters['project_id']) && is_numeric($filters['project_id']) ? (int) $filters['project_id'] : null;
        $sections = [];
        $summaryCards = [];
        $totalRecords = 0;
        $totalAmount = 0.0;

        foreach (($definition['sections'] ?? []) as $sectionConfig) {
            if (! is_array($sectionConfig)) {
                continue;
            }

            $section = $this->buildSection($sectionConfig, (int) $organization->id, $dateFrom, $dateTo, $projectId);
            $sections[] = $section;
            $totalRecords += (int) ($section['total'] ?? 0);
            $totalAmount += (float) ($section['amount_total'] ?? 0);

            $summaryCards[] = [
                'label' => (string) ($section['title'] ?? ''),
                'value' => (string) ($section['total'] ?? 0),
                'hint' => 'записей',
            ];
        }

        if ($totalAmount > 0) {
            array_unshift($summaryCards, [
                'label' => 'Сумма по разделам',
                'value' => $this->formatMoney($totalAmount),
                'hint' => 'по доступным суммовым полям',
            ]);
        }

        array_unshift($summaryCards, [
            'label' => 'Всего записей',
            'value' => (string) $totalRecords,
            'hint' => 'по отчету',
        ]);

        return [
            'report_type' => $reportType,
            'title' => (string) $definition['title'],
            'description' => (string) $definition['description'],
            'period_label' => $this->periodLabel($dateFrom, $dateTo),
            'generated_at' => Carbon::now()->format('d.m.Y H:i'),
            'organization_name' => (string) ($organization->name ?? 'Организация'),
            'generated_by' => $user?->name,
            'summary_cards' => $summaryCards,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string, string> $columns
     * @param string[] $amountColumns
     * @return array<string, mixed>
     */
    private function section(string $key, string $title, string $table, array $columns, array $amountColumns): array
    {
        return compact('key', 'title', 'table', 'columns', 'amountColumns');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildSection(array $config, int $organizationId, ?string $dateFrom, ?string $dateTo, ?int $projectId): array
    {
        $table = (string) ($config['table'] ?? '');
        $title = (string) ($config['title'] ?? $table);

        if ($table === '' || ! Schema::hasTable($table)) {
            return $this->emptySection($title);
        }

        $organizationColumn = $this->firstExistingColumn($table, ['organization_id']);
        if ($organizationColumn === null) {
            return $this->emptySection($title);
        }

        $query = DB::table($table)->where($organizationColumn, $organizationId);
        $dateColumn = $this->firstExistingColumn($table, ['created_at', 'date', 'occurred_at', 'received_at', 'shift_date', 'scanned_at', 'started_at']);
        $projectColumn = $this->firstExistingColumn($table, ['project_id']);

        if ($dateColumn !== null) {
            $this->applyPeriod($query, $dateColumn, $dateFrom, $dateTo);
        }

        if ($projectId !== null && $projectColumn !== null) {
            $query->where($projectColumn, $projectId);
        }

        $statusColumn = $this->firstExistingColumn($table, ['status', 'state']);
        $amountColumn = $this->firstExistingColumn($table, is_array($config['amountColumns'] ?? null) ? $config['amountColumns'] : []);
        $columns = $this->existingColumns($table, is_array($config['columns'] ?? null) ? $config['columns'] : []);
        $orderColumn = $dateColumn ?? $this->firstExistingColumn($table, ['id']);
        $total = (clone $query)->count();
        $amountTotal = $amountColumn !== null ? (float) (clone $query)->sum($amountColumn) : 0.0;

        return [
            'title' => $title,
            'total' => $total,
            'amount_total' => $amountTotal,
            'amount_label' => $amountColumn !== null ? 'Сумма' : null,
            'amount_value' => $amountColumn !== null ? $this->formatMoney($amountTotal) : null,
            'status_breakdown' => $statusColumn !== null ? $this->statusBreakdown($query, $statusColumn) : [],
            'headers' => array_values($columns),
            'rows' => $this->latestRows($query, $columns, $orderColumn),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySection(string $title): array
    {
        return [
            'title' => $title,
            'total' => 0,
            'amount_total' => 0,
            'amount_label' => null,
            'amount_value' => null,
            'status_breakdown' => [],
            'headers' => [],
            'rows' => [],
        ];
    }

    private function applyPeriod(Builder $query, string $dateColumn, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom !== null) {
            $query->whereDate($dateColumn, '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate($dateColumn, '<=', $dateTo);
        }
    }

    /**
     * @param string[] $candidates
     */
    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (is_string($column) && $column !== '' && Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    private function existingColumns(string $table, array $columns): array
    {
        $existing = [];

        foreach ($columns as $column => $label) {
            if (! is_string($column) || ! is_string($label)) {
                continue;
            }

            if (Schema::hasColumn($table, $column)) {
                $existing[$column] = $label;
            }
        }

        return $existing;
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function statusBreakdown(Builder $query, string $statusColumn): array
    {
        return (clone $query)
            ->select($statusColumn, DB::raw('count(*) as aggregate'))
            ->groupBy($statusColumn)
            ->orderByDesc('aggregate')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => [
                'label' => $this->formatValue($row->{$statusColumn} ?? null),
                'count' => (int) ($row->aggregate ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, string> $columns
     * @return array<int, array<int, string>>
     */
    private function latestRows(Builder $query, array $columns, ?string $orderColumn): array
    {
        if ($columns === []) {
            return [];
        }

        $rowQuery = (clone $query)->select(array_keys($columns))->limit(8);

        if ($orderColumn !== null) {
            $rowQuery->orderByDesc($orderColumn);
        }

        return $rowQuery
            ->get()
            ->map(fn (object $row): array => array_values(array_map(
                fn (string $column): string => $this->formatValue($row->{$column} ?? null),
                array_keys($columns)
            )))
            ->values()
            ->all();
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        if (is_numeric($value)) {
            return number_format((float) $value, 2, ',', ' ');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return Carbon::parse($value)->format('d.m.Y');
        }

        return mb_strimwidth((string) $value, 0, 80, '...');
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' ₽';
    }

    private function dateString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function periodLabel(?string $dateFrom, ?string $dateTo): string
    {
        if ($dateFrom === null && $dateTo === null) {
            return 'весь доступный период';
        }

        if ($dateFrom !== null && $dateTo !== null) {
            return Carbon::parse($dateFrom)->format('d.m.Y').' — '.Carbon::parse($dateTo)->format('d.m.Y');
        }

        if ($dateFrom !== null) {
            return 'с '.Carbon::parse($dateFrom)->format('d.m.Y');
        }

        return 'по '.Carbon::parse((string) $dateTo)->format('d.m.Y');
    }
}
