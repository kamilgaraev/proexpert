<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>КС-2 № {{ $act->act_document_number ?? $act->id }}</title>
    <style>
        @page {
            margin: 15mm;
            size: A4;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            line-height: 1.2;
        }
        .form-header {
            text-align: center;
            margin-bottom: 8px;
        }
        .form-title {
            font-size: 11pt;
            font-weight: bold;
        }
        .form-approval {
            font-size: 8pt;
            margin-bottom: 4px;
        }
        .form-code {
            font-size: 8pt;
            margin-bottom: 8px;
        }
        .section {
            margin-bottom: 6px;
            font-size: 9pt;
        }
        .section-label {
            font-weight: bold;
            display: inline-block;
            width: 35mm;
            vertical-align: top;
        }
        .section-value {
            display: inline-block;
            width: 150mm;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            font-size: 8pt;
        }
        th, td {
            border: 1px solid #000;
            padding: 3px;
            text-align: left;
        }
        th {
            background-color: #E0E0E0;
            font-weight: bold;
            text-align: center;
            font-size: 7pt;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .signatures {
            margin-top: 15px;
        }
        .signature-row {
            margin-bottom: 8px;
        }
        .amount-in-words {
            margin-top: 8px;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <div class="form-header">
        <div class="form-title">Унифицированная форма № КС-2</div>
        <div class="form-approval">Утверждена постановлением Госкомстата России от 11.11.99 № 100</div>
        <div class="form-code">Форма по ОКУД 322005</div>
    </div>

    <div class="section">
        <span class="section-label">Инвестор</span>
        <span class="section-value"></span>
    </div>

    <div class="section">
        <span class="section-label">Заказчик</span>
        <span class="section-value">
            {{ $customer_org->legal_name ?? $customer_org->name ?? '' }}
            @if($customer_org->tax_number), ИНН {{ $customer_org->tax_number }}@endif
            @if($customer_org->postal_code || $customer_org->city || $customer_org->address)
                , {{ $customer_org->postal_code ?? '' }}@if($customer_org->city) {{ $customer_org->city }} г@endif@if($customer_org->address), {{ $customer_org->address }}@endif
            @endif
        </span>
    </div>

    <div class="section">
        <span class="section-label">Заказчик (Генподрядчик)</span>
        <span class="section-value">
            {{ $customer_org->legal_name ?? $customer_org->name ?? '' }}
            @if($customer_org->tax_number), ИНН {{ $customer_org->tax_number }}@endif
            @if($customer_org->postal_code || $customer_org->city || $customer_org->address)
                , {{ $customer_org->postal_code ?? '' }}@if($customer_org->city) {{ $customer_org->city }} г@endif@if($customer_org->address), {{ $customer_org->address }}@endif
            @endif
        </span>
    </div>

    <div class="section">
        <span class="section-label">Подрядчик (Субподрядчик)</span>
        <span class="section-value">
            {{ $contractor->name ?? '' }}
            @if($contractor->inn), ИНН {{ $contractor->inn }}@endif
            @if($contractor->legal_address), {{ $contractor->legal_address }}@endif
        </span>
    </div>

    <div class="section">
        <span class="section-label">Стройка</span>
        <span class="section-value"></span>
    </div>

    <div class="section">
        <span class="section-label">Объект</span>
        <span class="section-value">{{ $project->name ?? '' }}</span>
    </div>

    <div class="section">
        <span class="section-label">Вид деятельности по ОКВЭД</span>
        <span class="section-value"></span>
    </div>

    <div class="section">
        <span class="section-label">Договор подряда (контракт)</span>
        <span class="section-value">
            номер {{ $contract->number }} дата {{ $contract->date->format('d.m.Y') }}
        </span>
    </div>

    <div class="section">
        <span class="section-label">Вид операции</span>
        <span class="section-value"></span>
    </div>

    <div class="section">
        <span class="section-label">Отчетный период</span>
        <span class="section-value">с {{ $act->act_date->format('d.m.Y') }} по {{ $act->act_date->format('d.m.Y') }}</span>
    </div>

    <div class="section">
        <span class="section-label">Номер документа</span>
        <span class="section-value">{{ $act->act_document_number ?? str_pad($act->id, 10, '0', STR_PAD_LEFT) }}</span>
        <span style="margin-left: 20px;">Дата составления</span>
        <span>{{ $act->act_date->format('d.m.Y') }}</span>
    </div>

    <div class="section">
        <span class="section-label">Сметная (договорная) стоимость в соответствии с договором подряда (субподряда)</span>
        <span class="section-value">{{ number_format($contract_amount, 2, ',', ' ') }} руб.</span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">№ п/п</th>
                <th style="width: 10%;">по смете</th>
                <th style="width: 30%;">Наименование работ</th>
                <th style="width: 12%;">Единица измерения</th>
                <th style="width: 10%;">Количество</th>
                <th style="width: 13%;">Цена за единицу, руб.</th>
                <th style="width: 13%;">Стоимость, руб.</th>
                <th style="width: 7%;">Примечание</th>
            </tr>
        </thead>
        <tbody>
            @foreach($works as $index => $work)
            @php
                $includedQuantity = $work->pivot->included_quantity ?? $work->quantity ?? 0;
                $includedAmount = $work->pivot->included_amount ?? $work->total_amount ?? 0;
                $unitPrice = $includedQuantity > 0 ? ($includedAmount / $includedQuantity) : ($work->price ?? 0);
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-center"></td>
                <td>{{ $work->workType->name ?? $work->description ?? '' }}</td>
                <td class="text-center">{{ $work->workType->measurementUnit->short_name ?? '' }}</td>
                <td class="text-right">{{ number_format($includedQuantity, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($unitPrice, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($includedAmount, 2, ',', ' ') }}</td>
                <td>{{ $work->pivot->notes ?? $work->notes ?? '' }}</td>
            </tr>
            @endforeach
            <tr>
                <td></td>
                <td></td>
                <td><strong>Итого по расценкам</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td><strong>НДС</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>{{ number_format($vat_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td><strong>Всего по Акту</strong></td>
                <td colspan="3"></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="amount-in-words">
        <strong>Сумма прописью по акту:</strong> {{ \App\Helpers\NumberToWordsHelper::amountToWords($total_amount) }}
    </div>

    <div class="signatures">
        <div class="signature-row">
            <strong>Сдал</strong>
        </div>
        <div class="signature-row">
            <strong>Генеральный Директор</strong>
            <span style="margin-left: 20px;">_____________</span>
            <span>/</span>
            <span>_______________</span>
            <span>/</span>
        </div>
        <div class="signature-row" style="margin-left: 20px;">
            <span>(подпись)</span>
            <span style="margin-left: 60px;">(расшифровка подписи)</span>
        </div>

        <div class="signature-row" style="margin-top: 15px;">
            <strong>Принял</strong>
        </div>
        <div class="signature-row">
            <strong>Генеральный Директор</strong>
            <span style="margin-left: 20px;">_____________</span>
            <span>/</span>
            <span>_______________</span>
            <span>/</span>
        </div>
        <div class="signature-row" style="margin-left: 20px;">
            <span>(подпись)</span>
            <span style="margin-left: 60px;">(расшифровка подписи)</span>
        </div>
    </div>
</body>
</html>
