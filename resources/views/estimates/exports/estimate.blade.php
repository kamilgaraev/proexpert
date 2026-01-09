<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Смета {{ $estimate['number'] ?? '' }}</title>
    <style>
        @page {
            margin: 15mm 10mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #000;
        }

        .header {
            background-color: #4A90E2;
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .title {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin: 15px 0;
        }

        .info-table {
            width: 100%;
            margin-bottom: 15px;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 4px 8px;
            border: none;
        }

        .info-table td:first-child {
            font-weight: bold;
            width: 30%;
        }

        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 8pt;
        }

        .main-table th {
            background-color: #E8E8E8;
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            font-weight: bold;
        }

        .main-table td {
            border: 1px solid #CCCCCC;
            padding: 4px;
        }

        .section-row {
            background-color: #F5F5F5;
            font-weight: bold;
            font-size: 9pt;
        }

        .section-total-row {
            background-color: #FFFACD;
            font-weight: bold;
        }

        .item-indent-1 {
            padding-left: 15px;
        }

        .item-indent-2 {
            padding-left: 30px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .totals-section {
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .totals-title {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .totals-table {
            width: 70%;
            margin-left: auto;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 5px 10px;
            border-top: 1px solid #000;
        }

        .totals-table td:first-child {
            text-align: right;
            width: 60%;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: bold;
        }

        .totals-table tr:last-child td {
            border-bottom: 3px double #000;
            background-color: #FFFACD;
            font-size: 10pt;
            padding: 8px 10px;
        }

        .signatures {
            margin-top: 30px;
            page-break-inside: avoid;
        }

        .signature-line {
            margin-bottom: 15px;
        }

        .signature-line strong {
            display: inline-block;
            width: 35%;
        }

        .signature-line span {
            border-bottom: 1px solid #000;
            display: inline-block;
            width: 60%;
            padding-left: 10px;
        }

        .page-break {
            page-break-after: always;
        }

        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 20px;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
    </style>
</head>
<body>
    {{-- Header with Prohelper branding --}}
    <div class="header">
        Prohelper
    </div>

    {{-- Title --}}
    <div class="title">
        ЛОКАЛЬНЫЙ СМЕТНЫЙ РАСЧЕТ
    </div>

    {{-- Estimate information --}}
    <table class="info-table">
        <tr>
            <td>Номер сметы:</td>
            <td>{{ $estimate['number'] ?? 'Не указан' }}</td>
        </tr>
        <tr>
            <td>Наименование:</td>
            <td>{{ $estimate['name'] ?? '' }}</td>
        </tr>
        <tr>
            <td>Дата составления:</td>
            <td>{{ $estimate['estimate_date'] ?? '' }}</td>
        </tr>
        <tr>
            <td>Организация:</td>
            <td>{{ $estimate['organization']['legal_name'] ?? $estimate['organization']['name'] ?? '' }}</td>
        </tr>
        @if($estimate['project'])
        <tr>
            <td>Проект:</td>
            <td>{{ $estimate['project']['name'] }}</td>
        </tr>
        @if($estimate['project']['address'])
        <tr>
            <td>Адрес объекта:</td>
            <td>{{ $estimate['project']['address'] }}</td>
        </tr>
        @endif
        @endif
        @if($estimate['contract'])
        <tr>
            <td>Договор:</td>
            <td>{{ $estimate['contract']['number'] }} - {{ $estimate['contract']['name'] }}</td>
        </tr>
        @endif
        <tr>
            <td>Статус:</td>
            <td>
                @if($estimate['status'] === 'draft') Черновик
                @elseif($estimate['status'] === 'in_review') На проверке
                @elseif($estimate['status'] === 'approved') Утверждена
                @elseif($estimate['status'] === 'rejected') Отклонена
                @elseif($estimate['status'] === 'archived') В архиве
                @else {{ $estimate['status'] }}
                @endif
            </td>
        </tr>
    </table>

    {{-- Main items table --}}
    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 5%;">№</th>
                <th style="width: 10%;">Код</th>
                <th style="width: 35%;">Наименование</th>
                <th style="width: 8%;">Ед.изм.</th>
                <th style="width: 10%;">Кол-во</th>
                @if($options['show_prices'])
                <th style="width: 12%;">Цена</th>
                <th style="width: 12%;">Сумма</th>
                @endif
                <th style="width: 8%;">Примечание</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sections as $section)
                {{-- Section header --}}
                <tr class="section-row">
                    <td colspan="{{ $options['show_prices'] ? 8 : 6 }}">
                        {{ $section['full_section_number'] }}. {{ $section['name'] }}
                    </td>
                </tr>

                {{-- Section items --}}
                @foreach($section['items'] as $item)
                    @include('estimates.exports.partials.item', ['item' => $item, 'indent' => 0, 'options' => $options])
                @endforeach

                {{-- Section total --}}
                @if($options['show_prices'] && $section['section_total_amount'] > 0)
                <tr class="section-total-row">
                    <td colspan="6" style="text-align: right; font-weight: bold;">ИТОГО ПО РАЗДЕЛУ:</td>
                    <td class="text-right">{{ number_format($section['section_total_amount'], 2, '.', ' ') }}</td>
                    <td></td>
                </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    {{-- Totals section --}}
    @if($options['show_prices'])
    <div class="totals-section">
        <div class="totals-title">ИТОГОВАЯ СВОДКА</div>
        <table class="totals-table">
            <tr>
                <td>Прямые затраты:</td>
                <td>{{ number_format($totals['total_direct_costs'], 2, '.', ' ') }}</td>
            </tr>
            <tr>
                <td>Накладные расходы ({{ number_format($totals['overhead_rate'], 2) }}%):</td>
                <td>{{ number_format($totals['total_overhead_costs'], 2, '.', ' ') }}</td>
            </tr>
            <tr>
                <td>Сметная прибыль ({{ number_format($totals['profit_rate'], 2) }}%):</td>
                <td>{{ number_format($totals['total_estimated_profit'], 2, '.', ' ') }}</td>
            </tr>
            <tr>
                <td>ИТОГО без НДС:</td>
                <td>{{ number_format($totals['total_amount'], 2, '.', ' ') }}</td>
            </tr>
            <tr>
                <td>НДС ({{ number_format($totals['vat_rate'], 0) }}%):</td>
                <td>{{ number_format($totals['vat_amount'], 2, '.', ' ') }}</td>
            </tr>
            <tr>
                <td>ВСЕГО С НДС:</td>
                <td>{{ number_format($totals['total_amount_with_vat'], 2, '.', ' ') }}</td>
            </tr>
        </table>
    </div>
    @endif

    {{-- Signatures --}}
    <div class="signatures">
        @foreach($options['signature_fields'] as $field)
        <div class="signature-line">
            <strong>{{ $field }}:</strong>
            <span>_______________________ (ФИО) "___"_____________ 20___ г.</span>
        </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <footer>
        Создано в системе Prohelper | {{ $metadata['export_date'] ? \Carbon\Carbon::parse($metadata['export_date'])->format('d.m.Y') : '' }}
    </footer>
</body>
</html>
