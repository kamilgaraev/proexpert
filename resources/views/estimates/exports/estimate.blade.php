<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Смета {{ $estimate['number'] ?? '' }}</title>
    <style>
        @page {
            margin: 12mm 15mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #1a1a1a;
            background: #ffffff;
        }

        /* Современный заголовок */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px 20px;
            text-align: center;
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 20px;
            border-radius: 8px;
            letter-spacing: 1px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .header-subtitle {
            font-size: 9pt;
            font-weight: normal;
            margin-top: 5px;
            opacity: 0.95;
            letter-spacing: 0.5px;
        }

        /* Основной заголовок документа */
        .title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 20px 0 25px 0;
            color: #2d3748;
            text-transform: uppercase;
            letter-spacing: 2px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }

        /* Информационная карточка */
        .info-card {
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table tr {
            border-bottom: 1px solid #e2e8f0;
        }

        .info-table tr:last-child {
            border-bottom: none;
        }

        .info-table td {
            padding: 8px 10px;
        }

        .info-table td:first-child {
            font-weight: bold;
            width: 35%;
            color: #4a5568;
        }

        .info-table td:last-child {
            color: #2d3748;
        }

        /* Основная таблица с позициями */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 8.5pt;
        }

        .main-table th {
            background: linear-gradient(180deg, #4a5568 0%, #2d3748 100%);
            color: white;
            border: 1px solid #2d3748;
            padding: 8px 5px;
            text-align: center;
            font-weight: bold;
            font-size: 8pt;
            letter-spacing: 0.3px;
        }

        .main-table td {
            border: 1px solid #cbd5e0;
            padding: 6px 5px;
            vertical-align: top;
        }

        .main-table tbody tr:hover {
            background-color: #f7fafc;
        }

        /* Строка раздела */
        .section-row {
            background: linear-gradient(180deg, #edf2f7 0%, #e2e8f0 100%);
            font-weight: bold;
            font-size: 9.5pt;
            color: #2d3748;
        }

        .section-row td {
            padding: 10px 8px;
            border: 2px solid #cbd5e0;
        }

        /* Итоги по разделу */
        .section-total-row {
            background: linear-gradient(180deg, #fef5e7 0%, #fdeaa3 100%);
            font-weight: bold;
            color: #744210;
        }

        .section-total-row td {
            padding: 7px 5px;
            border: 1px solid #f6ad55;
        }

        /* Отступы для вложенных элементов */
        .item-indent-1 {
            padding-left: 20px;
            color: #4a5568;
            font-size: 8pt;
        }

        .item-indent-2 {
            padding-left: 40px;
            color: #718096;
            font-size: 7.5pt;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        /* Секция итогов */
        .totals-section {
            margin-top: 30px;
            margin-bottom: 25px;
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
        }

        .totals-title {
            text-align: center;
            font-size: 13pt;
            font-weight: bold;
            margin-bottom: 15px;
            color: #2d3748;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .totals-table {
            width: 75%;
            margin: 0 auto;
            border-collapse: collapse;
        }

        .totals-table tr {
            border-bottom: 1px solid #cbd5e0;
        }

        .totals-table td {
            padding: 8px 15px;
        }

        .totals-table td:first-child {
            text-align: right;
            width: 60%;
            color: #4a5568;
            font-weight: 500;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: bold;
            color: #2d3748;
            font-size: 10pt;
        }

        .totals-table tr:last-child {
            border-top: 3px double #667eea;
            border-bottom: 3px double #667eea;
        }

        .totals-table tr:last-child td {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 11pt;
            padding: 12px 15px;
            font-weight: bold;
        }

        /* Блок подписей */
        .signatures {
            margin-top: 40px;
            page-break-inside: avoid;
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .signatures-title {
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 20px;
            color: #2d3748;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .signature-line {
            margin-bottom: 18px;
            display: flex;
            align-items: center;
        }

        .signature-line strong {
            display: inline-block;
            width: 35%;
            color: #4a5568;
            font-size: 9pt;
        }

        .signature-line span {
            border-bottom: 2px solid #cbd5e0;
            display: inline-block;
            flex: 1;
            padding: 5px 10px;
            color: #718096;
            font-size: 8pt;
        }

        /* Футер */
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 25px;
            text-align: center;
            font-size: 8pt;
            color: #a0aec0;
            background: linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(247,250,252,1) 100%);
            padding-top: 8px;
        }

        .page-break {
            page-break-after: always;
        }

        /* Значок статуса */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 8pt;
            font-weight: bold;
        }

        .status-approved {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-draft {
            background: #e2e8f0;
            color: #2d3748;
        }

        .status-review {
            background: #fef5e7;
            color: #744210;
        }

        .status-rejected {
            background: #fed7d7;
            color: #742a2a;
        }
    </style>
</head>
<body>
    {{-- Header with Prohelper branding --}}
    <div class="header">
        <div>PROHELPER</div>
        <div class="header-subtitle">Система управления проектами и смет</div>
    </div>

    {{-- Title --}}
    <div class="title">
        Локальный Сметный Расчет
    </div>

    {{-- Estimate information --}}
    <div class="info-card">
        <table class="info-table">
            <tr>
                <td>Номер сметы:</td>
                <td><strong>{{ $estimate['number'] ?? 'Не указан' }}</strong></td>
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
                    @php
                        $statusClass = match($estimate['status']) {
                            'approved' => 'status-approved',
                            'in_review' => 'status-review',
                            'rejected' => 'status-rejected',
                            default => 'status-draft'
                        };
                        $statusText = match($estimate['status']) {
                            'draft' => 'Черновик',
                            'in_review' => 'На проверке',
                            'approved' => '✓ Утверждена',
                            'rejected' => 'Отклонена',
                            'archived' => 'В архиве',
                            default => $estimate['status']
                        };
                    @endphp
                    <span class="status-badge {{ $statusClass }}">{{ $statusText }}</span>
                </td>
            </tr>
        </table>
    </div>

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
        <div class="signatures-title">Подписи ответственных лиц</div>
        @foreach($options['signature_fields'] as $field)
        <div class="signature-line">
            <strong>{{ $field }}:</strong>
            <span>_____________________________ (ФИО) "____"_____________ 20___ г.</span>
        </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <footer>
        <strong>Prohelper</strong> - Профессиональная система сметных расчётов | 
        Экспорт: {{ $metadata['export_date'] ? \Carbon\Carbon::parse($metadata['export_date'])->format('d.m.Y H:i') : '' }}
    </footer>
</body>
</html>
