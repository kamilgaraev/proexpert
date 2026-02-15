<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Отчет по выполненным работам</title>
    <style>
        body {
            font-family: 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif;
            font-size: 9px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #27ae60;
            padding-bottom: 10px;
        }
        .report-title {
            font-size: 16px;
            font-weight: bold;
            color: #27ae60;
            text-transform: uppercase;
        }
        .metadata {
            margin-top: 5px;
            font-size: 8px;
            color: #666;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .main-table th {
            background-color: #2ecc71;
            color: white;
            padding: 6px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #27ae60;
        }
        .main-table td {
            padding: 5px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }
        .main-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .numeric {
            text-align: right;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="report-title">Отчет по выполненным работам</div>
        <div class="metadata">
            Период: {{ $filters['date_from'] ?? '...' }} — {{ $filters['date_to'] ?? '...' }} | 
            Сформирован: {{ $generated_at }}
        </div>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 10%">Дата</th>
                <th style="width: 15%">Проект</th>
                <th style="width: 20%">Вид работы</th>
                <th style="width: 10%">Объем</th>
                <th style="width: 10%">Цена</th>
                <th style="width: 12%">Сумма</th>
                <th>Исполнитель</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entries as $entry)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($entry['completion_date'])->format('d.m.Y') }}</td>
                    <td>{{ $entry['project']['name'] ?? '-' }}</td>
                    <td>{{ $entry['workType']['name'] ?? '-' }}</td>
                    <td class="numeric">{{ number_format($entry['quantity'], 2, ',', ' ') }} {{ $entry['workType']['measurementUnit']['symbol'] ?? '' }}</td>
                    <td class="numeric">{{ number_format($entry['unit_price'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($entry['total_price'], 2, ',', ' ') }}</td>
                    <td>{{ $entry['user']['name'] ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Документ сформирован автоматически в системе ProHelper
    </div>
</body>
</html>
