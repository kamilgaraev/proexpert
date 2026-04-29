@php
    $formatDate = static function ($date): string {
        if (!$date) {
            return '';
        }

        return $date instanceof \Carbon\CarbonInterface
            ? $date->format('d.m.Y')
            : \Illuminate\Support\Carbon::parse($date)->format('d.m.Y');
    };

    $formatMoney = static fn ($value): string => number_format((float) ($value ?? 0), 2, ',', ' ');
    $join = static fn (array $parts): string => implode(', ', array_values(array_filter($parts, static fn ($value): bool => trim((string) $value) !== '')));
    $workRows = collect($works ?? []);

    $actDate = $act->act_date ?? now();
    $periodStartDate = $period_start ?? $actDate;
    $periodEndDate = $period_end ?? $actDate;
    $documentNumber = $act->act_document_number ?? str_pad((string) ($act->id ?? 0), 10, '0', STR_PAD_LEFT);
    $customerName = $customer_org->legal_name ?? $customer_org->name ?? '';
    $customerLine = $join([
        $customerName,
        ($customer_org->tax_number ?? null) ? 'ИНН ' . $customer_org->tax_number : null,
        $customer_org->postal_code ?? null,
        ($customer_org->city ?? null) ? $customer_org->city . ' г' : null,
        $customer_org->address ?? null,
    ]);
    $contractorLine = $join([
        $contractor->name ?? null,
        ($contractor->inn ?? null) ? 'ИНН ' . $contractor->inn : null,
        $contractor->legal_address ?? null,
    ]);
    $projectName = $project->name ?? '';
    $contractDate = $formatDate($contract->date ?? null);
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>КС-2 № {{ $documentNumber }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm 10mm 10mm;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            color: #000;
            font-family: "DejaVu Serif", serif;
            font-size: 9.5pt;
            line-height: 1.12;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .top-note {
            font-size: 8.6pt;
            line-height: 1.18;
            padding-right: 4mm;
            text-align: right;
        }

        .header-area {
            min-height: 80mm;
            position: relative;
        }

        .party-block {
            margin-right: 98mm;
            padding-top: 25mm;
        }

        .code-area {
            position: absolute;
            right: 4mm;
            top: 15mm;
            width: 90mm;
        }

        .party-table td {
            border: 0;
            padding: 1.5mm 0 0;
            vertical-align: bottom;
        }

        .party-label {
            font-size: 10.2pt;
            white-space: nowrap;
            width: 43mm;
        }

        .party-line {
            border-bottom: 0.7pt solid #000;
            min-height: 4.3mm;
            padding: 0 1mm 0.3mm;
        }

        .party-hint {
            font-size: 6.9pt;
            line-height: 1;
            padding-top: 0.5mm;
            text-align: center;
        }

        .code-table td {
            font-size: 9pt;
            padding: 0;
        }

        .code-label {
            border: 0;
            padding-right: 1.5mm;
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
            width: 52mm;
        }

        .code-box {
            border: 0.7pt solid #000;
            height: 6mm;
            text-align: center;
            vertical-align: middle;
            width: 34mm;
        }

        .code-head {
            height: 5mm;
        }

        .code-tall {
            height: 10mm;
        }

        .document-block {
            margin-top: 2mm;
        }

        .doc-row td {
            border: 0;
            padding: 0;
            vertical-align: bottom;
        }

        .doc-title-cell {
            text-align: right;
            width: 52%;
        }

        .doc-word {
            font-size: 13pt;
            font-weight: bold;
            padding-right: 2mm;
        }

        .doc-meta-cell {
            padding-left: 2mm;
            width: 24%;
        }

        .period-meta-cell {
            padding-left: 4mm;
            width: 24%;
        }

        .meta-table th,
        .meta-table td {
            border: 0.7pt solid #000;
            font-size: 9pt;
            font-weight: normal;
            height: 6.8mm;
            padding: 0.5mm 1mm;
            text-align: center;
            vertical-align: middle;
        }

        .main-title {
            font-size: 13pt;
            font-weight: bold;
            margin: 1mm 0 7mm;
            text-align: center;
            text-transform: uppercase;
        }

        .contract-amount {
            font-size: 9.7pt;
            margin: 0 0 4mm;
            white-space: nowrap;
        }

        .amount-line {
            border-bottom: 0.7pt solid #000;
            display: inline-block;
            margin-left: 2mm;
            min-width: 136mm;
            padding-right: 2mm;
            text-align: right;
        }

        .official-table {
            table-layout: fixed;
        }

        .official-table th,
        .official-table td {
            border: 0.7pt solid #000;
            font-size: 8.4pt;
            font-weight: normal;
            padding: 1mm 1.2mm;
            vertical-align: top;
        }

        .official-table th {
            line-height: 1.05;
            text-align: center;
            vertical-align: middle;
        }

        .official-table thead {
            display: table-header-group;
        }

        .official-table tr {
            page-break-inside: avoid;
        }

        .row-number td {
            height: 4.5mm;
            padding: 0.4mm 1mm;
            text-align: center;
        }

        .work-row td,
        .blank-row td {
            height: 7mm;
        }

        .total-row td {
            height: 6mm;
            vertical-align: middle;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

    </style>
</head>
<body>
    <div class="header-area">
        <div class="top-note">
            <div>Унифицированная форма № КС-2</div>
            <div>Утверждена постановлением Госкомстата России</div>
            <div>от 11 ноября 1999 г. № 100</div>
        </div>

        <div class="code-area">
            <table class="code-table">
                <tr>
                    <td class="code-label"></td>
                    <td class="code-box code-head">Код</td>
                </tr>
                <tr>
                    <td class="code-label">Форма по ОКУД</td>
                    <td class="code-box">0322005</td>
                </tr>
                <tr>
                    <td class="code-label">по ОКПО</td>
                    <td class="code-box code-tall"></td>
                </tr>
                <tr>
                    <td class="code-label">по ОКПО</td>
                    <td class="code-box code-tall"></td>
                </tr>
                <tr>
                    <td class="code-label">по ОКПО</td>
                    <td class="code-box code-tall"></td>
                </tr>
                <tr>
                    <td class="code-label">Вид деятельности по ОКДП</td>
                    <td class="code-box"></td>
                </tr>
                <tr>
                    <td class="code-label">Договор подряда (контракт)</td>
                    <td class="code-box">номер {{ $contract->number ?? '' }}</td>
                </tr>
                <tr>
                    <td class="code-label"></td>
                    <td class="code-box">дата {{ $contractDate }}</td>
                </tr>
                <tr>
                    <td class="code-label">Вид операции</td>
                    <td class="code-box"></td>
                </tr>
            </table>
        </div>

        <div class="party-block">
                <table class="party-table">
                    <tr>
                        <td class="party-label">Инвестор</td>
                        <td>
                            <div class="party-line"></div>
                            <div class="party-hint">организация, адрес, телефон, факс</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="party-label">Заказчик (Генподрядчик)</td>
                        <td>
                            <div class="party-line">{{ $customerLine }}</div>
                            <div class="party-hint">организация, адрес, телефон, факс</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="party-label">Подрядчик (Субподрядчик)</td>
                        <td>
                            <div class="party-line">{{ $contractorLine }}</div>
                            <div class="party-hint">организация, адрес, телефон, факс</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="party-label">Стройка</td>
                        <td>
                            <div class="party-line">{{ $projectName }}</div>
                            <div class="party-hint">наименование, адрес</div>
                        </td>
                    </tr>
                    <tr>
                        <td class="party-label">Объект</td>
                        <td>
                            <div class="party-line">{{ $projectName }}</div>
                            <div class="party-hint">наименование</div>
                        </td>
                    </tr>
                </table>
        </div>
    </div>

    <div class="document-block">
        <table class="doc-row">
            <tr>
                <td class="doc-title-cell"><div class="doc-word">АКТ</div></td>
                <td class="doc-meta-cell">
                    <table class="meta-table">
                        <tr>
                            <th>Номер документа</th>
                            <th>Дата составления</th>
                        </tr>
                        <tr>
                            <td>{{ $documentNumber }}</td>
                            <td>{{ $formatDate($actDate) }}</td>
                        </tr>
                    </table>
                </td>
                <td class="period-meta-cell">
                    <table class="meta-table">
                        <tr>
                            <th colspan="2">Отчетный период</th>
                        </tr>
                        <tr>
                            <th>с</th>
                            <th>по</th>
                        </tr>
                        <tr>
                            <td>{{ $formatDate($periodStartDate) }}</td>
                            <td>{{ $formatDate($periodEndDate) }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        <div class="main-title">О приемке выполненных работ</div>
    </div>

    <div class="contract-amount">
        Сметная (договорная) стоимость в соответствии с договором подряда (субподряда)
        <span class="amount-line">{{ $formatMoney($contract_amount ?? 0) }}</span>
        руб.
    </div>

    <table class="official-table">
        <colgroup>
            <col style="width: 6.8%;">
            <col style="width: 6.8%;">
            <col style="width: 41.2%;">
            <col style="width: 10.3%;">
            <col style="width: 6.2%;">
            <col style="width: 9.6%;">
            <col style="width: 9.6%;">
            <col style="width: 9.5%;">
        </colgroup>
        <thead>
            <tr>
                <th colspan="2">Номер</th>
                <th rowspan="2">Наименование работ</th>
                <th rowspan="2">Номер единичной расценки</th>
                <th rowspan="2">Единица измерения</th>
                <th colspan="3">Выполнено работ</th>
            </tr>
            <tr>
                <th>по порядку</th>
                <th>позиции по смете</th>
                <th>количество</th>
                <th>цена за единицу, руб.</th>
                <th>стоимость, руб.</th>
            </tr>
            <tr class="row-number">
                <td>1</td>
                <td>2</td>
                <td>3</td>
                <td>4</td>
                <td>5</td>
                <td>6</td>
                <td>7</td>
                <td>8</td>
            </tr>
        </thead>
        <tbody>
            @foreach($workRows as $index => $work)
                @php
                    $includedQuantity = (float) ($work['quantity'] ?? 0);
                    $includedAmount = (float) ($work['amount'] ?? 0);
                    $unitPrice = $work['unit_price'] ?? ($includedQuantity > 0 ? ($includedAmount / $includedQuantity) : 0);
                @endphp
                <tr class="work-row">
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center"></td>
                    <td>{{ $work['title'] ?? '' }}</td>
                    <td class="text-center">{{ $work['code'] ?? '' }}</td>
                    <td class="text-center">{{ $work['unit'] ?? '' }}</td>
                    <td class="text-right">{{ $formatMoney($includedQuantity) }}</td>
                    <td class="text-right">{{ $formatMoney($unitPrice) }}</td>
                    <td class="text-right">{{ $formatMoney($includedAmount) }}</td>
                </tr>
            @endforeach

            @for($blank = $workRows->count(); $blank < 3; $blank++)
                <tr class="blank-row">
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor

            <tr class="total-row">
                <td colspan="5" class="text-right">Итого</td>
                <td class="text-right">{{ $formatMoney($workRows->sum(fn (array $line): float => (float) ($line['quantity'] ?? 0))) }}</td>
                <td class="text-center">X</td>
                <td class="text-right">{{ $formatMoney($total_amount ?? 0) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
