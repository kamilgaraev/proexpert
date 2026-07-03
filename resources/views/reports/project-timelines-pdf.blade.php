<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @include('pdf.partials.most-brand-styles')
        body {
            font-family: 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif;
            font-size: 8px;
            line-height: 1.35;
            color: #263238;
            margin: 0;
            padding: 15px;
        }
        .header {
            margin-bottom: 14px;
            border-bottom: 2px solid #3f51b5;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #3f51b5;
        }
        .metadata {
            margin-top: 4px;
            color: #607d8b;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px;
            background-color: #eef0fb;
            border: 1px solid #c5cae9;
            text-align: center;
        }
        .total-val {
            font-size: 11px;
            font-weight: bold;
            color: #303f9f;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            background-color: #3f51b5;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #303f9f;
        }
        .main-table td {
            padding: 4px;
            border: 1px solid #d5d9f2;
            vertical-align: top;
        }
        .numeric {
            text-align: right;
            white-space: nowrap;
        }
        .status {
            font-weight: bold;
            white-space: nowrap;
        }
        .flag-overdue {
            color: #c0392b;
            font-weight: bold;
        }
        .flag-risk {
            color: #b9770e;
            font-weight: bold;
        }
        .progress-cell {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    @include('pdf.partials.most-brand-header')
    <div class="header">
        <div class="report-title">{{ $title }}</div>
        <div class="metadata">Сформирован: {{ $generated_at }}</div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Проектов: <div class="total-val">{{ $totals['total_projects'] }}</div></td>
            <td>Активных: <div class="total-val">{{ $totals['active_projects'] }}</div></td>
            <td>Просроченных: <div class="total-val" style="color: #c0392b">{{ $totals['overdue_projects'] }}</div></td>
            <td>В зоне риска: <div class="total-val" style="color: #b9770e">{{ $totals['at_risk_projects'] }}</div></td>
            <td>Среднее выполнение: <div class="total-val">{{ $totals['avg_completion_percent'] }}%</div></td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th>Проект</th>
                <th>Заказчик</th>
                <th>Статус</th>
                <th>Начало</th>
                <th>Окончание</th>
                <th>План</th>
                <th>Прошло</th>
                <th>Осталось</th>
                <th>Выполнение</th>
                <th>Время</th>
                <th>Прогноз</th>
                <th>Риск</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data as $project)
                <tr>
                    <td>{{ $project['name'] }}</td>
                    <td>{{ $project['customer'] ?? '-' }}</td>
                    <td class="status">{{ $project['status'] }}</td>
                    <td>{{ $project['start_date'] ? \Carbon\Carbon::parse($project['start_date'])->format('d.m.Y') : '-' }}</td>
                    <td>{{ $project['end_date'] ? \Carbon\Carbon::parse($project['end_date'])->format('d.m.Y') : '-' }}</td>
                    <td class="numeric">{{ $project['planned_duration_days'] }}</td>
                    <td class="numeric">{{ $project['elapsed_days'] }}</td>
                    <td class="numeric">{{ $project['remaining_days'] }}</td>
                    <td class="numeric progress-cell">{{ $project['completion_percent'] }}%</td>
                    <td class="numeric progress-cell">{{ $project['time_progress_percent'] }}%</td>
                    <td>{{ $project['estimated_completion_date'] ? \Carbon\Carbon::parse($project['estimated_completion_date'])->format('d.m.Y') : '-' }}</td>
                    <td>
                        @if($project['is_overdue'])
                            <span class="flag-overdue">Просрочка {{ $project['delay_days'] }} дн.</span>
                        @elseif($project['is_at_risk'])
                            <span class="flag-risk">Есть риск</span>
                        @else
                            В графике
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" style="text-align: center;">По выбранным параметрам проекты не найдены.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @include('pdf.partials.most-brand-footer')
</body>
</html>
