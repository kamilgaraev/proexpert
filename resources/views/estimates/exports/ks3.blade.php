<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>КС-3 № {{ $act->number }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 14pt;
            font-weight: bold;
        }
        .info {
            margin-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
            font-size: 9pt;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .signatures {
            margin-top: 30px;
        }
        .signature-block {
            margin-bottom: 20px;
        }
        .total-info {
            margin-top: 20px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Унифицированная форма № КС-3</div>
        <div class="title">СПРАВКА № {{ $act->number }} от {{ $act->act_date->format('d.m.Y') }}</div>
        <div>о стоимости выполненных работ и затрат</div>
    </div>

    <div class="info">
        <p><strong>Заказчик:</strong> {{ $contract->customer_organization ?? '' }}</p>
        <p><strong>Подрядчик:</strong> {{ $contract->contractor->full_name ?? '' }}</p>
        <p><strong>Договор:</strong> № {{ $contract->number }} от {{ $contract->contract_date->format('d.m.Y') }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">№ п/п</th>
                <th style="width: 30%;">Наименование работ и затрат</th>
                <th style="width: 15%;">Стоимость выполненных работ с начала года, руб.</th>
                <th style="width: 15%;">в том числе за отчетный период</th>
                <th style="width: 15%;">Выполнено работ с начала строительства, руб.</th>
                <th style="width: 15%;">Остаток по смете, руб.</th>
                <th style="width: 5%;">Примечание</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">1</td>
                <td>Строительные работы</td>
                <td class="text-right">{{ number_format($total_amount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($total_amount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($total_amount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($remaining_amount, 2, ',', ' ') }}</td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>ИТОГО:</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($remaining_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="total-info">
        <p>Итого стоимость выполненных работ: {{ number_format($total_amount, 2, ',', ' ') }} руб.</p>
    </div>

    <div class="signatures">
        <div class="signature-block">
            <p><strong>Заказчик:</strong></p>
            <p>_____________ / _______________ /</p>
        </div>
        
        <div class="signature-block">
            <p><strong>Подрядчик:</strong></p>
            <p>_____________ / _______________ /</p>
        </div>
    </div>
</body>
</html>

