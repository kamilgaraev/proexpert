<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Отчет по остаткам на складах</title>
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
            border-bottom: 2px solid #9b59b6;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #9b59b6;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px;
            background-color: #f5eef8;
            border: 1px solid #d7bde2;
            text-align: center;
        }
        .total-val { font-size: 11px; font-weight: bold; color: #8e44ad; }
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            background-color: #9b59b6;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #8e44ad;
        }
        .main-table td {
            padding: 4px;
            border: 1px solid #d7bde2;
        }
        .numeric { text-align: right; }
        .critical { color: #e74c3c; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="report-title">Отчет по остаткам на складах</div>
        <div class="metadata">Сформирован: {{ $generated_at }}</div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Позиций: <div class="total-val">{{ $totals['total_items'] }}</div></td>
            <td>Общее кол-во: <div class="total-val">{{ number_format($totals['total_quantity'], 2, ',', ' ') }}</div></td>
            <td>Общая стоимость: <div class="total-val">{{ number_format($totals['total_value'], 2, ',', ' ') }} ₽</div></td>
            <td>Критических: <div class="total-val" style="color: #e74c3c">{{ $totals['critical_items'] }}</div></td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th>Материал</th>
                <th>Код</th>
                <th>Категория</th>
                <th>Склад</th>
                <th>Доступно</th>
                <th>Резерв</th>
                <th>Всего</th>
                <th>Цена</th>
                <th>Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $s)
                <tr class="{{ $s['is_critical'] ? 'critical' : '' }}">
                    <td>{{ $s['material_name'] }}</td>
                    <td>{{ $s['material_code'] }}</td>
                    <td>{{ $s['category'] }}</td>
                    <td>{{ $s['warehouse'] }}</td>
                    <td class="numeric">{{ number_format($s['available_quantity'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($s['reserved_quantity'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($s['total_quantity'], 2, ',', ' ') }} {{ $s['unit'] }}</td>
                    <td class="numeric">{{ number_format($s['unit_price'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($s['total_value'], 2, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
