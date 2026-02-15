<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Отчет по контрактам и платежам</title>
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
            border-bottom: 2px solid #e67e22;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #e67e22;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px;
            background-color: #fef5e7;
            border: 1px solid #f5cda7;
            text-align: center;
        }
        .total-val { font-size: 11px; font-weight: bold; color: #d35400; }
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            background-color: #f39c12;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #e67e22;
        }
        .main-table td {
            padding: 4px;
            border: 1px solid #f5cda7;
        }
        .numeric { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="report-title">Отчет по контрактам и платежам</div>
        <div class="metadata">Сформирован: {{ $generated_at }}</div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Всего контрактов: <div class="total-val">{{ $totals['total_contracts'] }}</div></td>
            <td>Общая сумма: <div class="total-val">{{ number_format($totals['total_amount'], 2, ',', ' ') }} ₽</div></td>
            <td>Выполнено: <div class="total-val">{{ number_format($totals['total_completed'], 2, ',', ' ') }} ₽</div></td>
            <td>Оплачено: <div class="total-val">{{ number_format($totals['total_paid'], 2, ',', ' ') }} ₽</div></td>
            <td>Задолженность: <div class="total-val">{{ number_format($totals['total_debt'], 2, ',', ' ') }} ₽</div></td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th>№ Контракта</th>
                <th>Дата</th>
                <th>Подрядчик</th>
                <th>Проект</th>
                <th>Сумма</th>
                <th>Выполнено</th>
                <th>Оплачено</th>
                <th>Долг</th>
                <th>%</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $c)
                <tr>
                    <td>{{ $c['number'] }}</td>
                    <td>{{ $c['date'] }}</td>
                    <td>{{ $c['contractor'] }}</td>
                    <td>{{ $c['project'] }}</td>
                    <td class="numeric">{{ number_format($c['total_amount'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($c['completed_amount'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($c['paid_amount'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($c['debt_amount'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ $c['completion_percentage'] }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
