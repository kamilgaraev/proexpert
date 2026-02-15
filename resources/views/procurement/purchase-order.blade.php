<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заказ поставщику №{{ $order->order_number }}</title>
    <style>
        @page {
            margin: 1cm;
            size: A4 landscape;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.2;
            color: #000;
        }
        
        .header {
            margin-bottom: 5px;
        }
        
        .header h1 {
            font-size: 16pt;
            font-weight: bold;
            margin: 0;
            padding: 0;
        }
        
        .header-line {
            border: none;
            border-top: 2px solid #000;
            margin: 5px 0 10px 0;
        }
        
        .contractors {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        
        .contractors td {
            padding: 2px 0;
            vertical-align: top;
        }
        
        .contractors .label {
            width: 100px;
            font-weight: normal;
        }
        
        .contractors .value {
            font-weight: bold;
        }
        
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        table.items th, table.items td {
            border: 1px solid #000;
            padding: 4px;
            font-size: 9pt;
        }
        
        table.items th {
            background-color: #fff;
            font-weight: bold;
            text-align: center;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        
        .totals {
            width: 100%;
            margin-top: 5px;
        }
        
        .total-row {
            text-align: right;
            padding: 2px 0;
        }
        
        .total-label {
            display: inline-block;
            width: 150px;
            font-weight: bold;
            text-align: right;
            margin-right: 10px;
        }
        
        .total-value {
            display: inline-block;
            width: 120px;
            font-weight: bold;
            text-align: right;
            border-bottom: 1px solid #fff;
        }
        
        .summary-block {
            margin-top: 15px;
            margin-bottom: 20px;
        }
        
        .summary-line {
            margin-bottom: 3px;
        }
        
        .footer-line {
            border: none;
            border-top: 2px solid #000;
            margin: 10px 0;
        }
        
        .signatures {
            width: 100%;
            margin-top: 20px;
        }
        
        .signatures td {
            padding: 10px 0;
        }
        
        .sig-line {
            display: inline-block;
            width: 200px;
            border-bottom: 1px solid #000;
            margin: 0 5px;
        }
        
        .notes-block {
            margin-top: 10px;
            font-size: 9pt;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Заказ поставщику № {{ $order->order_number }} от {{ $date_formatted }}</h1>
    </div>

    <hr class="header-line">

    <table class="contractors">
        <tr>
            <td class="label">Исполнитель:</td>
            <td class="value">{{ $supplier_string }}</td>
        </tr>
        <tr>
            <td class="label">Заказчик:</td>
            <td class="value">{{ $organization_string }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 30px;">№</th>
                <th>Товары (работы, услуги)</th>
                <th style="width: 70px;">Кол-во</th>
                <th style="width: 40px;">Ед.</th>
                <th style="width: 90px;">Цена</th>
                <th style="width: 100px;">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $index => $item)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left">{{ $item->material_name }}</td>
                <td class="text-right">{{ number_format($item->quantity, 2, ',', ' ') }}</td>
                <td class="text-center">{{ $item->unit }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($item->total_price, 2, ',', ' ') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row">
            <span class="total-label">Итого:</span>
            <span class="total-value">{{ number_format($order->total_amount, 2, ',', ' ') }}</span>
        </div>
        <div class="total-row">
            <span class="total-label">В том числе НДС:</span>
            <span class="total-value">0,00</span>
        </div>
    </div>

    <div class="summary-block">
        <div class="summary-line">
            Всего наименований {{ count($items) }}, на сумму {{ number_format($order->total_amount, 2, ',', ' ') }} руб.
        </div>
        <div class="summary-line">
            <strong>{{ $total_amount_words }}</strong>
        </div>
    </div>

    @if($order->notes)
    <div class="notes-block">
        Примечание: {{ $order->notes }}
    </div>
    @endif

    <hr class="footer-line">

    <table class="signatures">
        <tr>
            <td style="width: 50%;">
                <strong>Исполнитель</strong> <span class="sig-line"></span> ({{ $supplier->contact_person ?? '_____________________' }})
            </td>
            <td style="width: 50%;">
                <strong>Заказчик</strong> <span class="sig-line"></span> (_____________________)
            </td>
        </tr>
    </table>
</body>
</html>
