<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Расширенный отчет по журналу работ</title>
    <style>
        @page { margin: 12mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            line-height: 1.35;
            color: #1f2933;
        }
        h1 {
            font-size: 14px;
            text-align: center;
            margin: 0 0 12px;
        }
        h2 {
            font-size: 11px;
            margin: 14px 0 6px;
        }
        .meta {
            margin-bottom: 12px;
        }
        .meta p {
            margin: 3px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        th, td {
            border: 1px solid #cfd8dc;
            padding: 4px;
            vertical-align: top;
        }
        th {
            background-color: #edf2f7;
            font-weight: bold;
        }
        .summary td {
            text-align: center;
            background-color: #f8fafc;
        }
        .summary-value {
            display: block;
            font-size: 11px;
            font-weight: bold;
            margin-top: 2px;
        }
        .entry-title {
            font-weight: bold;
            background-color: #f8fafc;
        }
        .muted {
            color: #607d8b;
        }
        .numeric {
            text-align: right;
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <h1>РАСШИРЕННЫЙ ОТЧЕТ ПО ЖУРНАЛУ РАБОТ</h1>

    <div class="meta">
        <p><strong>Проект:</strong> {{ $journal->project->name ?? '-' }}</p>
        <p><strong>Журнал №:</strong> {{ $journal->journal_number ?? $journal->id }}</p>
        <p><strong>Период:</strong> {{ $period_from->format('d.m.Y') }} — {{ $period_to->format('d.m.Y') }}</p>
    </div>

    <table class="summary">
        <tr>
            <td>Записей<span class="summary-value">{{ $totals['total_entries'] }}</span></td>
            <td>Рабочих<span class="summary-value">{{ $totals['total_workers'] }}</span></td>
            <td>Человеко-часов<span class="summary-value">{{ number_format((float) $totals['total_work_hours'], 1, ',', ' ') }}</span></td>
            <td>Материалов<span class="summary-value">{{ $totals['total_materials'] }}</span></td>
            <td>Оборудования<span class="summary-value">{{ $totals['total_equipment'] }}</span></td>
        </tr>
    </table>

    <h2>Записи журнала</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 8%;">№</th>
                <th style="width: 10%;">Дата</th>
                <th>Описание работ</th>
                @if($options['include_volumes'] ?? true)
                    <th style="width: 18%;">Объемы</th>
                @endif
                @if($options['include_workers'] ?? true)
                    <th style="width: 16%;">Рабочие</th>
                @endif
                @if($options['include_equipment'] ?? true)
                    <th style="width: 16%;">Оборудование</th>
                @endif
                @if($options['include_materials'] ?? true)
                    <th style="width: 16%;">Материалы</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td class="entry-title">{{ $entry->entry_number }}</td>
                    <td>{{ $entry->entry_date?->format('d.m.Y') ?? '-' }}</td>
                    <td>
                        {{ $entry->work_description ?: '-' }}
                        <div class="muted">Статус: {{ $entry->status?->label() ?? '-' }}</div>
                    </td>
                    @if($options['include_volumes'] ?? true)
                        <td>
                            @forelse($entry->workVolumes as $volume)
                                <div>
                                    {{ $volume->workType?->name ?? $volume->estimateItem?->name ?? 'Работа' }}:
                                    {{ number_format((float) $volume->quantity, 2, ',', ' ') }}
                                    {{ $volume->measurementUnit?->short_name ?? $volume->workType?->measurementUnit?->short_name ?? $volume->estimateItem?->measurementUnit?->short_name ?? '' }}
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                    @endif
                    @if($options['include_workers'] ?? true)
                        <td>
                            @forelse($entry->workers as $worker)
                                <div>
                                    {{ $worker->specialty }}:
                                    {{ $worker->workers_count }} чел.,
                                    {{ number_format((float) $worker->hours_worked, 1, ',', ' ') }} ч
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                    @endif
                    @if($options['include_equipment'] ?? true)
                        <td>
                            @forelse($entry->equipment as $equipment)
                                <div>
                                    {{ $equipment->equipment_name }}:
                                    {{ number_format((float) $equipment->quantity, 2, ',', ' ') }}
                                    @if($equipment->hours_used !== null)
                                        / {{ number_format((float) $equipment->hours_used, 1, ',', ' ') }} ч
                                    @endif
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                    @endif
                    @if($options['include_materials'] ?? true)
                        <td>
                            @forelse($entry->materials as $material)
                                <div>
                                    {{ $material->material_name }}:
                                    {{ number_format((float) $material->quantity, 2, ',', ' ') }}
                                    {{ $material->measurement_unit }}
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center;">За выбранный период утвержденных записей не найдено.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
