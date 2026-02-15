<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заказ поставщику №{{ $order->order_number }}</title>
    <style>
        @page {
            margin: 2cm;
            size: A4;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            color: #000;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 16pt;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .header .doc-number {
            font-size: 12pt;
            margin: 5px 0;
        }
        
        .section {
            margin: 20px 0;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 12pt;
            margin-bottom: 10px;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        table th, table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-size: 10pt;
        }
        
        table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        
        .info-block {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ccc;
            background-color: #f9f9f9;
        }
        
        .info-row {
            margin: 5px 0;
        }
        
        .info-label {
            display: inline-block;
            width: 180px;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 40px;
        }
        
        .signature-block {
            margin-top: 30px;
        }
        
        .signature-line {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
        }
        
        .signature-label {
            width: 150px;
        }
        
        .signature-space {
            flex: 1;
            border-bottom: 1px solid #000;
            margin: 0 10px;
        }
        
        .signature-name {
            width: 200px;
            text-align: left;
        }
        
        .total-section {
            margin-top: 20px;
            font-size: 12pt;
        }
        
        .total-row {
            display: flex;
            justify-content: flex-end;
            margin: 5px 0;
        }
        
        .total-label {
            font-weight: bold;
            margin-right: 20px;
        }
        
        .gost-reference {
            font-size: 9pt;
            color: #666;
            margin-top: 30px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>ЗАКАЗ ПОСТАВЩИКУ</h1>
        <div class="doc-number">№ {{ $order->order_number }} от {{ $date_formatted }}</div>
    </div>

    <div class="section">
        <div class="section-title">1. ЗАКАЗЧИК</div>
        <div class="info-block">
            <div class="info-row">
                <span class="info-label">Наименование:</span>
                <span>{{ $organization->legal_name ?? $organization->name ?? 'Не указано' }}</span>
            </div>
            @if(isset($organization->tax_number))
            <div class="info-row">
                <span class="info-label">ИНН:</span>
                <span>{{ $organization->tax_number }}</span>
            </div>
            @endif
            @if(isset($organization->registration_number))
            <div class="info-row">
                <span class="info-label">ОГРН:</span>
                <span>{{ $organization->registration_number }}</span>
            </div>
            @endif
            @if(isset($organization->address))
            <div class="info-row">
                <span class="info-label">Юридический адрес:</span>
                <span>{{ $organization->address }}@if($organization->city), {{ $organization->city }}@endif</span>
            </div>
            @endif
            @if(isset($organization->phone))
            <div class="info-row">
                <span class="info-label">Телефон:</span>
                <span>{{ $organization->phone }}</span>
            </div>
            @endif
        </div>
    </div>

    <div class="section">
        <div class="section-title">2. ПОСТАВЩИК</div>
        <div class="info-block">
            <div class="info-row">
                <span class="info-label">Наименование:</span>
                <span>{{ $supplier->name ?? 'Не указано' }}</span>
            </div>
            @if(isset($supplier->inn))
            <div class="info-row">
                <span class="info-label">ИНН:</span>
                <span>{{ $supplier->inn }}</span>
            </div>
            @endif
            @if(isset($supplier->contact_person))
            <div class="info-row">
                <span class="info-label">Контактное лицо:</span>
                <span>{{ $supplier->contact_person }}</span>
            </div>
            @endif
            @if(isset($supplier->phone))
            <div class="info-row">
                <span class="info-label">Телефон:</span>
                <span>{{ $supplier->phone }}</span>
            </div>
            @endif
            @if(isset($supplier->email))
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span>{{ $supplier->email }}</span>
            </div>
            @endif
            @if(isset($supplier->address))
            <div class="info-row">
                <span class="info-label">Адрес:</span>
                <span>{{ $supplier->address }}</span>
            </div>
            @endif
        </div>
    </div>

    @if(count($items) > 0)
    <div class="section">
        <div class="section-title">3. СПЕЦИФИКАЦИЯ ЗАКАЗА</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">№</th>
                    <th>Наименование товара/услуги</th>
                    <th style="width: 80px;">Единица измерения</th>
                    <th style="width: 80px;">Количество</th>
                    <th style="width: 100px;">Цена за ед.</th>
                    <th style="width: 100px;">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $index => $item)
                <tr>
                    <td style="text-align: center;">{{ $index + 1 }}</td>
                    <td>{{ $item->material_name ?? 'Не указано' }}</td>
                    <td style="text-align: center;">{{ $item->unit ?? 'шт.' }}</td>
                    <td style="text-align: right;">{{ number_format($item->quantity ?? 0, 2, ',', ' ') }}</td>
                    <td style="text-align: right;">{{ number_format($item->unit_price ?? 0, 2, ',', ' ') }}</td>
                    <td style="text-align: right;">{{ number_format($item->total_price ?? 0, 2, ',', ' ') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="total-section">
        <div class="total-row">
            <span class="total-label">ИТОГО:</span>
            <span>{{ number_format($order->total_amount, 2, ',', ' ') }} {{ $order->currency }}</span>
        </div>
        @if($total_amount_words)
        <div class="total-row" style="font-size: 10pt; font-style: italic;">
            <span>Всего на сумму: {{ $total_amount_words }}</span>
        </div>
        @endif
    </div>

    @if($order->delivery_date)
    <div class="section">
        <div class="section-title">4. УСЛОВИЯ ПОСТАВКИ</div>
        <div class="info-row">
            <span class="info-label">Срок поставки:</span>
            <span>{{ \Carbon\Carbon::parse($order->delivery_date)->format('d.m.Y') }}</span>
        </div>
    </div>
    @endif

    @if($order->notes)
    <div class="section">
        <div class="section-title">5. ПРИМЕЧАНИЯ</div>
        <p>{{ $order->notes }}</p>
    </div>
    @endif

    <div class="footer">
        <div class="signature-block">
            <div class="signature-line">
                <div class="signature-label">От заказчика:</div>
                <div class="signature-space"></div>
                <div class="signature-name">(_____________)</div>
            </div>
            
            <div class="signature-line">
                <div class="signature-label">От поставщика:</div>
                <div class="signature-space"></div>
                <div class="signature-name">(_____________)</div>
            </div>
        </div>
        
        <div class="gost-reference">
            Документ составлен в соответствии с требованиями ГОСТ Р 7.0.97-2016
        </div>
    </div>
</body>
</html>
