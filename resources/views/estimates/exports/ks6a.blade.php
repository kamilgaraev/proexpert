@php
    $formatDate = static function ($date): string {
        if (!$date) {
            return '';
        }

        return $date instanceof \Carbon\CarbonInterface
            ? $date->format('d.m.Y')
            : \Illuminate\Support\Carbon::parse($date)->format('d.m.Y');
    };

    $formatNumber = static function ($value, int $precision = 2, bool $emptyZero = true): string {
        $number = (float) ($value ?? 0);

        if ($emptyZero && abs($number) < 0.00001) {
            return '';
        }

        $formatted = number_format($number, $precision, ',', ' ');

        return rtrim(rtrim($formatted, '0'), ',');
    };

    $formatMoney = static fn ($value, bool $emptyZero = true): string => $formatNumber($value, 2, $emptyZero);
    $formatThousands = static fn ($value): string => $formatNumber(((float) ($value ?? 0)) / 1000, 3, false);
    $join = static fn (array $parts): string => implode(', ', array_values(array_filter($parts, static fn ($value): bool => trim((string) $value) !== '')));

    $rows = collect($rows ?? []);
    $monthGroups = array_values($month_groups ?? []);
    while (count($monthGroups) < 2) {
        $monthGroups[] = ['key' => null, 'title' => ''];
    }

    $customerLine = $join([
        $customer_org?->legal_name ?? $customer_org?->name ?? null,
        ($customer_org?->tax_number ?? null) ? 'ИНН ' . $customer_org->tax_number : null,
        $customer_org?->postal_code ?? null,
        ($customer_org?->city ?? null) ? $customer_org->city . ' г' : null,
        $customer_org?->address ?? null,
        $customer_org?->phone ?? null,
    ]);
    $contractorLine = $join([
        $contractor?->name ?? null,
        ($contractor?->inn ?? null) ? 'ИНН ' . $contractor->inn : null,
        $contractor?->legal_address ?? null,
        $contractor?->phone ?? null,
    ]);
    $projectLine = $join([$project?->name ?? null, $project?->address ?? null]);
    $objectLine = $project?->name ?? '';
    $workName = $contract->subject ?? $project?->name ?? '';
    $contractDate = $formatDate($contract->date ?? null);
    $contractAmount = (float) ($contract->total_amount ?? $contract->base_amount ?? $total_estimate_amount ?? 0);
    $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P'];
    $firstMonth = $monthGroups[0];
    $secondMonth = $monthGroups[1];
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>КС-6а № {{ $contract->number ?? $contract->id ?? '' }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 6mm 7mm 7mm;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            color: #000;
            font-family: "DejaVu Serif", serif;
            font-size: 7.4pt;
            line-height: 1.08;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .header-area {
            min-height: 79mm;
            position: relative;
        }

        .party-block {
            margin-right: 86mm;
            padding-top: 18mm;
        }

        .party-table td {
            border: 0;
            padding: 1.2mm 0 0;
            vertical-align: bottom;
        }

        .party-label {
            font-size: 7.6pt;
            font-weight: bold;
            white-space: nowrap;
            width: 24mm;
        }

        .party-line {
            border-bottom: 0.7pt solid #000;
            min-height: 3.8mm;
            padding: 0 1mm 0.2mm;
        }

        .party-hint {
            font-size: 5.7pt;
            line-height: 1;
            padding-top: 0.35mm;
            text-align: center;
        }

        .top-note {
            font-size: 6.8pt;
            line-height: 1.15;
            position: absolute;
            right: 31mm;
            text-align: center;
            top: 5mm;
            width: 70mm;
        }

        .code-area {
            position: absolute;
            right: 3mm;
            top: 11mm;
            width: 57mm;
        }

        .code-table td {
            font-size: 6.8pt;
            padding: 0;
        }

        .code-label {
            border: 0;
            padding-right: 1mm;
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
            width: 27mm;
        }

        .code-box {
            border: 0.7pt solid #000;
            height: 5mm;
            text-align: center;
            vertical-align: middle;
            width: 27mm;
        }

        .code-head {
            height: 4.5mm;
        }

        .code-tall {
            height: 7.5mm;
        }

        .title-block {
            margin-top: 17mm;
            text-align: center;
        }

        .title-main {
            font-size: 8.7pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .title-sub {
            font-size: 7.5pt;
            font-weight: bold;
            margin-top: 1mm;
        }

        .work-line {
            margin-top: 5mm;
            text-align: left;
        }

        .work-line span {
            border-bottom: 0.7pt solid #000;
            display: inline-block;
            min-width: 126mm;
            padding: 0 1mm 0.2mm;
            text-align: left;
        }

        .amount-line {
            margin-top: 4mm;
            white-space: nowrap;
        }

        .amount-line span {
            border-bottom: 0.7pt solid #000;
            display: inline-block;
            min-width: 33mm;
            padding: 0 1mm 0.2mm;
            text-align: center;
        }

        .sign-row {
            margin-top: 4mm;
            width: 100%;
        }

        .sign-row td {
            border: 0;
            padding: 0;
            vertical-align: bottom;
        }

        .sign-line {
            border-bottom: 0.7pt solid #000;
            display: inline-block;
            min-width: 30mm;
            padding: 0 1mm 0.2mm;
            text-align: center;
        }

        .sign-hint {
            display: inline-block;
            font-size: 5.6pt;
            min-width: 30mm;
            text-align: center;
        }

        .official-table {
            table-layout: fixed;
        }

        .official-table th,
        .official-table td {
            border: 0.7pt solid #000;
            font-size: 5.85pt;
            font-weight: normal;
            line-height: 1.02;
            padding: 0.6mm 0.5mm;
            vertical-align: middle;
        }

        .official-table th {
            text-align: center;
        }

        .official-table thead {
            display: table-header-group;
        }

        .official-table tr {
            page-break-inside: avoid;
        }

        .letter-row td,
        .number-row td {
            height: 3.5mm;
            padding: 0.25mm;
            text-align: center;
        }

        .work-row td {
            height: 6.8mm;
            vertical-align: top;
        }

        .blank-row td,
        .total-row td {
            height: 4.4mm;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header-area">
        <div class="top-note">
            <div>Унифицированная форма № КС-6а</div>
            <div>Утверждена постановлением Госкомстата России</div>
            <div>от 11 ноября 1999 года № 100</div>
        </div>

        <div class="code-area">
            <table class="code-table">
                <tr>
                    <td class="code-label"></td>
                    <td class="code-box code-head">Код</td>
                </tr>
                <tr>
                    <td class="code-label">Форма по ОКУД</td>
                    <td class="code-box">0322006</td>
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
                    <td class="code-box"></td>
                </tr>
                <tr>
                    <td class="code-label"></td>
                    <td class="code-box"></td>
                </tr>
                <tr>
                    <td class="code-label">Вид деятельности по ОКДП</td>
                    <td class="code-box code-tall"></td>
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
                    <td class="party-label">Заказчик:</td>
                    <td>
                        <div class="party-line">{{ $customerLine }}</div>
                        <div class="party-hint">организация, адрес, телефон, факс</div>
                    </td>
                </tr>
                <tr>
                    <td class="party-label">Подрядчик:</td>
                    <td>
                        <div class="party-line">{{ $contractorLine }}</div>
                        <div class="party-hint">организация, адрес, телефон, факс</div>
                    </td>
                </tr>
                <tr>
                    <td class="party-label">Стройка:</td>
                    <td>
                        <div class="party-line">{{ $projectLine }}</div>
                        <div class="party-hint">наименование, адрес</div>
                    </td>
                </tr>
                <tr>
                    <td class="party-label">Объект:</td>
                    <td>
                        <div class="party-line">{{ $objectLine }}</div>
                        <div class="party-hint">наименование</div>
                    </td>
                </tr>
            </table>

            <div class="title-block">
                <div class="title-main">Журнал учета выполненных работ</div>
                <div class="title-sub">с начала строительства</div>
            </div>

            <div class="work-line">
                на <span>{{ $workName }}</span>
                <div class="party-hint" style="margin-left: 12mm; width: 126mm;">наименование работ и затрат</div>
            </div>

            <div class="amount-line">
                Сметная (договорная) стоимость в соответствии с договором подряда (субподряда)
                <span>{{ $formatThousands($contractAmount) }}</span>
                тыс. руб.
            </div>

            <table class="sign-row">
                <tr>
                    <td style="width: 50%;">
                        Составил
                        <span class="sign-line"></span>
                        <span class="sign-line"></span>
                        <span class="sign-line"></span><br>
                        <span style="display: inline-block; width: 15mm;"></span>
                        <span class="sign-hint">должность</span>
                        <span class="sign-hint">подпись</span>
                        <span class="sign-hint">расшифровка подписи</span>
                    </td>
                    <td>
                        Проверил
                        <span class="sign-line"></span>
                        <span class="sign-line"></span>
                        <span class="sign-line"></span><br>
                        <span style="display: inline-block; width: 15mm;"></span>
                        <span class="sign-hint">должность</span>
                        <span class="sign-hint">подпись</span>
                        <span class="sign-hint">расшифровка подписи</span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <table class="official-table">
        <colgroup>
            <col style="width: 7mm;">
            <col style="width: 8mm;">
            <col style="width: 34mm;">
            <col style="width: 18mm;">
            <col style="width: 14mm;">
            <col style="width: 15mm;">
            <col style="width: 14mm;">
            <col style="width: 17mm;">
            <col style="width: 14mm;">
            <col style="width: 17mm;">
            <col style="width: 23mm;">
            <col style="width: 14mm;">
            <col style="width: 17mm;">
            <col style="width: 23mm;">
            <col style="width: 14mm;">
            <col style="width: 17mm;">
        </colgroup>
        <thead>
            <tr class="letter-row">
                @foreach($letters as $letter)
                    <td>{{ $letter }}</td>
                @endforeach
            </tr>
            <tr>
                <th colspan="2">Номер</th>
                <th rowspan="3">Конструктивные элементы и виды работ</th>
                <th rowspan="3">Номер единичной расценки</th>
                <th rowspan="3">Единица измерения</th>
                <th rowspan="3">Цена за единицу, руб.</th>
                <th rowspan="3">Количество работ по смете</th>
                <th rowspan="3">Сметная (договорная) стоимость, руб.</th>
                <th colspan="3">Выполнено работ</th>
                <th colspan="3">Выполнено работ</th>
                <th colspan="2">Остаток работ {{ $remaining_label }}</th>
            </tr>
            <tr>
                <th rowspan="2">п/п</th>
                <th rowspan="2">поз. по смете</th>
                <th colspan="3">{{ $firstMonth['title'] ?? '' }}</th>
                <th colspan="3">{{ $secondMonth['title'] ?? '' }}</th>
                <th rowspan="2">количество</th>
                <th rowspan="2">стоимость</th>
            </tr>
            <tr>
                <th>количество</th>
                <th>стоимость</th>
                <th>стоимость фактически выполненных работ с начала строительства, руб.</th>
                <th>количество</th>
                <th>стоимость</th>
                <th>стоимость фактически выполненных работ с начала строительства, руб.</th>
            </tr>
            <tr class="number-row">
                @for($index = 1; $index <= 16; $index++)
                    <td>{{ $index }}</td>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @php
                    $firstMonthData = $firstMonth['key'] ? ($row['months'][$firstMonth['key']] ?? []) : [];
                    $secondMonthData = $secondMonth['key'] ? ($row['months'][$secondMonth['key']] ?? []) : [];
                @endphp
                <tr class="work-row">
                    <td class="text-center">{{ $row['number'] }}</td>
                    <td class="text-center">{{ $row['estimate_position'] }}</td>
                    <td>{{ $row['title'] }}</td>
                    <td class="text-center">{{ $row['rate_code'] }}</td>
                    <td class="text-center">{{ $row['unit'] }}</td>
                    <td class="text-right">{{ $formatMoney($row['unit_price'] ?? 0) }}</td>
                    <td class="text-right">{{ $formatNumber($row['estimate_quantity'] ?? 0, 3) }}</td>
                    <td class="text-right">{{ $formatMoney($row['estimate_amount'] ?? 0) }}</td>
                    <td class="text-right">{{ $formatNumber($firstMonthData['quantity'] ?? 0, 3) }}</td>
                    <td class="text-right">{{ $formatMoney($firstMonthData['amount'] ?? 0) }}</td>
                    <td class="text-right">{{ $formatMoney($firstMonthData['from_start'] ?? 0) }}</td>
                    <td class="text-right">{{ $formatNumber($secondMonthData['quantity'] ?? 0, 3) }}</td>
                    <td class="text-right">{{ $formatMoney($secondMonthData['amount'] ?? 0) }}</td>
                    <td class="text-right">{{ $formatMoney($secondMonthData['from_start'] ?? 0) }}</td>
                    <td class="text-right">{{ $formatNumber($row['remaining_quantity'] ?? 0, 3) }}</td>
                    <td class="text-right">{{ $formatMoney($row['remaining_amount'] ?? 0) }}</td>
                </tr>
            @endforeach

            @for($blank = $rows->count(); $blank < 3; $blank++)
                <tr class="blank-row">
                    @for($cell = 1; $cell <= 16; $cell++)
                        <td></td>
                    @endfor
                </tr>
            @endfor

            @php
                $firstTotals = $firstMonth['key']
                    ? [
                        'quantity' => $rows->sum(fn (array $row): float => (float) ($row['months'][$firstMonth['key']]['quantity'] ?? 0)),
                        'amount' => $rows->sum(fn (array $row): float => (float) ($row['months'][$firstMonth['key']]['amount'] ?? 0)),
                        'from_start' => $rows->sum(fn (array $row): float => (float) ($row['months'][$firstMonth['key']]['from_start'] ?? 0)),
                    ]
                    : ['quantity' => 0, 'amount' => 0, 'from_start' => 0];
                $secondTotals = $secondMonth['key']
                    ? [
                        'quantity' => $rows->sum(fn (array $row): float => (float) ($row['months'][$secondMonth['key']]['quantity'] ?? 0)),
                        'amount' => $rows->sum(fn (array $row): float => (float) ($row['months'][$secondMonth['key']]['amount'] ?? 0)),
                        'from_start' => $rows->sum(fn (array $row): float => (float) ($row['months'][$secondMonth['key']]['from_start'] ?? 0)),
                    ]
                    : ['quantity' => 0, 'amount' => 0, 'from_start' => 0];
            @endphp
            <tr class="total-row bold">
                <td colspan="7" class="text-right">Итого:</td>
                <td class="text-right">{{ $formatMoney($total_estimate_amount ?? 0) }}</td>
                <td class="text-right">{{ $formatNumber($firstTotals['quantity'], 3) }}</td>
                <td class="text-right">{{ $formatMoney($firstTotals['amount']) }}</td>
                <td class="text-right">{{ $formatMoney($firstTotals['from_start']) }}</td>
                <td class="text-right">{{ $formatNumber($secondTotals['quantity'], 3) }}</td>
                <td class="text-right">{{ $formatMoney($secondTotals['amount']) }}</td>
                <td class="text-right">{{ $formatMoney($secondTotals['from_start']) }}</td>
                <td></td>
                <td class="text-right">{{ $formatMoney($total_remaining_amount ?? 0) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
