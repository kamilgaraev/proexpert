<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #4a90e2;
            padding-bottom: 10px;
        }
        .report-title {
            font-size: 18px;
            font-weight: bold;
            color: #4a90e2;
            text-transform: uppercase;
        }
        .metadata {
            margin-top: 5px;
            font-size: 9px;
            color: #666;
        }
        .filters-section {
            margin-bottom: 15px;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
        }
        .filters-title {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 11px;
            color: #444;
        }
        .totals-grid {
            margin-bottom: 20px;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 8px;
            background-color: #f0f4f8;
            border: 1px solid #d1d9e6;
            text-align: center;
        }
        .total-value {
            font-size: 14px;
            font-weight: bold;
            color: #2c3e50;
        }
        .total-label {
            font-size: 9px;
            color: #7f8c8d;
            text-transform: uppercase;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .main-table th {
            background-color: #4a90e2;
            color: white;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #357abd;
        }
        .main-table td {
            padding: 6px 8px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }
        .main-table tr:nth-child(even) {
            background-color: #fcfcfc;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 8px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .status-approved { background-color: #d4edda; color: #155724; }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-rejected { background-color: #f8d7da; color: #721c24; }
        .status-draft { background-color: #e2e3e5; color: #383d41; }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }
        .page-number:before {
            content: "Страница " counter(page);
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="report-title">{{ $title }}</div>
        <div class="metadata">
            Период: {{ $filters['date_from'] ?? '...' }} — {{ $filters['date_to'] ?? '...' }} | 
            Сформирован: {{ $generated_at }}
        </div>
    </div>

    <div class="totals-grid">
        <table class="totals-table">
            <tr>
                <td>
                    <div class="total-label">Записей</div>
                    <div class="total-value">{{ $totals['total_entries'] }}</div>
                </td>
                <td>
                    <div class="total-label">Всего часов</div>
                    <div class="total-value">{{ number_format($totals['total_hours'], 1) }}ч</div>
                </td>
                <td>
                    <div class="total-label">Общая стоимость</div>
                    <div class="total-value">{{ number_format($totals['total_cost'], 2, ',', ' ') }} ₽</div>
                </td>
                <td>
                    <div class="total-label">Утверждено</div>
                    <div class="total-value">{{ number_format($totals['approved_hours'], 1) }}ч</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 12%">Дата</th>
                <th style="width: 15%">Сотрудник</th>
                <th style="width: 15%">Проект</th>
                <th style="width: 15%">Тип работ</th>
                <th>Описание</th>
                <th style="width: 8%">Часы</th>
                <th style="width: 10%">Статус</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $entry)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('d.m.Y') }}</td>
                    <td>{{ $entry['user'] }}</td>
                    <td>{{ $entry['project'] ?? '-' }}</td>
                    <td>{{ $entry['work_type'] ?? '-' }}</td>
                    <td>{{ $entry['title'] }}</td>
                    <td style="text-align: right;">{{ number_format($entry['hours'], 1) }}</td>
                    <td style="text-align: center;">
                        <span class="status-badge status-{{ $entry['status'] }}">
                            {{ $entry['status'] }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Документ сформирован автоматически в системе ProHelper. 
        <span class="page-number"></span>
    </div>
</body>
</html>
