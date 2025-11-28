<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>КС-3 № {{ $act->act_document_number ?? $act->id }}</title>
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
        .total-info {
            margin-top: 8px;
            font-weight: bold;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <div class="form-header">
        <div class="form-title">Унифицированная форма № КС-3</div>
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

    <table>
        <thead>
            <tr>
                <th style="width: 8%;">№ по порядку</th>
                <th style="width: 35%;">Наименование пусковых комплексов, объектов, видов работ, оборудования, затрат</th>
                <th style="width: 8%;">Код</th>
                <th colspan="3" style="width: 35%;">Стоимость выполненных работ и затрат, руб.</th>
                <th style="width: 14%;">Примечание</th>
            </tr>
            <tr>
                <th></th>
                <th></th>
                <th></th>
                <th>с начала проведения работ</th>
                <th>с начала года</th>
                <th>в том числе за отчетный период</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">1</td>
                <td>Всего работ и затрат, включаемых в стоимость работ</td>
                <td class="text-center"></td>
                <td class="text-right">{{ number_format($total_amount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($total_amount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($total_amount, 2, ',', ' ') }}</td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td>в том числе:</td>
                <td colspan="5"></td>
            </tr>
            @php $workIndex = 2; @endphp
            @foreach($act->completedWorks as $work)
            @php
                $includedAmount = $work->pivot->included_amount ?? $work->total_amount ?? 0;
            @endphp
            <tr>
                <td class="text-center">{{ $workIndex }}</td>
                <td>{{ $work->workType->name ?? $work->description ?? '' }}</td>
                <td class="text-center">{{ $work->workType->code ?? '' }}</td>
                <td class="text-right">{{ number_format($includedAmount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($includedAmount, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($includedAmount, 2, ',', ' ') }}</td>
                <td></td>
            </tr>
            @php $workIndex++; @endphp
            @endforeach
            <tr>
                <td></td>
                <td><strong>ИТОГО:</strong></td>
                <td></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>Сумма НДС</strong></td>
                <td></td>
                <td class="text-right"><strong>{{ number_format($vat_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($vat_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($vat_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td><strong>Всего с учетом НДС</strong></td>
                <td></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="total-info">
        <p>Итого стоимость выполненных работ: {{ number_format($total_amount, 2, ',', ' ') }} руб.</p>
    </div>

    <div class="signatures">
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
