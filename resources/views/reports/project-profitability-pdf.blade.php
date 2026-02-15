<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Отчет по рентабельности проектов</title>
    <style>
        body {
            font-family: 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif;
            font-size: 8px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 15px;
        }
        .header {
            margin-bottom: 15px;
            border-bottom: 2px solid #e74c3c;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #e74c3c;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px;
            background-color: #fce4e4;
            border: 1px solid #f9bcbc;
            text-align: center;
        }
        .total-val { font-size: 11px; font-weight: bold; color: #c0392b; }
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            background-color: #e74c3c;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #c0392b;
        }
        .main-table td {
            padding: 4px;
            border: 1px solid #f9bcbc;
        }
        .numeric { text-align: right; }
        .loss { color: #c0392b; font-weight: bold; }
        .profit { color: #27ae60; }
    </style>
</head>
<body>
    <div class="header">
        <div class="report-title">Отчет по рентабельности проектов</div>
        <div class="metadata">Сформирован: {{ $generated_at }}</div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Проектов: <div class="total-val">{{ $totals['total_projects'] }}</div></td>
            <td>Прибыльных: <div class="total-val" style="color: #27ae60">{{ $totals['profitable_projects'] }}</div></td>
            <td>Убыточных: <div class="total-val" style="color: #c0392b">{{ $totals['loss_making_projects'] }}</div></td>
            <td>Общая прибыль: <div class="total-val">{{ number_format($totals['total_profit'], 2, ',', ' ') }} ₽</div></td>
            <td>Средняя рентабельность: <div class="total-val">{{ $totals['avg_profitability'] }}%</div></td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th>Проект</th>
                <th>Заказчик</th>
                <th>Доход</th>
                <th>Подрядчики</th>
                <th>Материалы</th>
                <th>Зарплата</th>
                <th>Всего расх.</th>
                <th>Прибыль</th>
                <th>%</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $p)
                <tr>
                    <td>{{ $p['name'] }}</td>
                    <td>{{ $p['customer'] }}</td>
                    <td class="numeric">{{ number_format($p['income'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($p['contractor_costs'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($p['material_costs'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($p['labor_costs'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($p['total_expenses'], 2, ',', ' ') }}</td>
                    <td class="numeric {{ $p['profit'] < 0 ? 'loss' : 'profit' }}">
                        {{ number_format($p['profit'], 2, ',', ' ') }}
                    </td>
                    <td class="numeric {{ $p['profitability_percent'] < 0 ? 'loss' : '' }}">
                        {{ $p['profitability_percent'] }}%
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
