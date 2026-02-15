<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Отчет по расчетам с подрядчиками</title>
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
            border-bottom: 2px solid #3498db;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #3498db;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px;
            background-color: #ebf5fb;
            border: 1px solid #aed6f1;
            text-align: center;
        }
        .total-val { font-size: 11px; font-weight: bold; color: #2980b9; }
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            background-color: #3498db;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #2980b9;
        }
        .main-table td {
            padding: 4px;
            border: 1px solid #aed6f1;
        }
        .numeric { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <div class="report-title">Отчет по расчетам с подрядчиками</div>
        <div class="metadata">Сформирован: {{ $generated_at }}</div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Подрядчиков: <div class="total-val">{{ $totals['total_contractors'] }}</div></td>
            <td>Сумма контрактов: <div class="total-val">{{ number_format($totals['total_contract_amount'], 2, ',', ' ') }} ₽</div></td>
            <td>Выполнено: <div class="total-val">{{ number_format($totals['total_completed'], 2, ',', ' ') }} ₽</div></td>
            <td>Оплачено: <div class="total-val">{{ number_format($totals['total_paid'], 2, ',', ' ') }} ₽</div></td>
            <td>Долг: <div class="total-val">{{ number_format($totals['total_debt'], 2, ',', ' ') }} ₽</div></td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th>Подрядчик</th>
                <th>ИНН</th>
                <th>Контакт</th>
                <th>Сумма контрактов</th>
                <th>Выполнено</th>
                <th>Оплачено</th>
                <th>Долг</th>
                <th>Статус</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $c)
                <tr>
                    <td>{{ $c['name'] }}</td>
                    <td>{{ $c['inn'] }}</td>
                    <td>{{ $c['contact_person'] }}</td>
                    <td class="numeric">{{ number_format($c['total_contract_amount'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($c['total_completed'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($c['total_paid'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($c['debt_amount'], 2, ',', ' ') }}</td>
                    <td>{{ $c['settlement_status'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
