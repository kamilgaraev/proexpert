@php
    $formatDate = static function ($date): string {
        if (!$date) {
            return '';
        }

        return $date instanceof \Carbon\CarbonInterface
            ? $date->format('d.m.Y')
            : \Illuminate\Support\Carbon::parse($date)->format('d.m.Y');
    };

    $join = static fn (array $parts): string => implode(', ', array_values(array_filter($parts, static fn ($value): bool => trim((string) $value) !== '')));

    $project = $journal->project ?? null;
    $contract = $journal->contract ?? null;
    $organization = $journal->organization ?? $project?->organization ?? $contract?->organization ?? null;
    $contractor = $contract?->contractor ?? null;
    $entries = collect($entries ?? []);

    $customerLine = $join([
        $organization?->legal_name ?? $organization?->name ?? null,
        ($organization?->tax_number ?? null) ? 'ИНН ' . $organization->tax_number : null,
        $organization?->postal_code ?? null,
        ($organization?->city ?? null) ? $organization->city . ' г' : null,
        $organization?->address ?? null,
        $organization?->phone ?? null,
    ]);
    $contractorLine = $join([
        $contractor?->name ?? null,
        ($contractor?->inn ?? null) ? 'ИНН ' . $contractor->inn : null,
        $contractor?->legal_address ?? null,
        $contractor?->phone ?? null,
    ]);
    $projectLine = $join([$project?->name ?? null, $project?->address ?? null]);
    $contractLine = $contract
        ? $join(['№ ' . ($contract->number ?? ''), $formatDate($contract->date ?? null)])
        : '';
    $journalNumber = $journal->journal_number ?? $journal->id ?? '';
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>КС-6 № {{ $journalNumber }}</title>
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
            font-size: 8.6pt;
            line-height: 1.12;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .top-area {
            min-height: 58mm;
            position: relative;
        }

        .top-note {
            font-size: 8pt;
            line-height: 1.18;
            padding-right: 5mm;
            text-align: right;
        }

        .code-area {
            position: absolute;
            right: 0;
            top: 15mm;
            width: 78mm;
        }

        .code-table td {
            font-size: 8pt;
            padding: 0;
        }

        .code-label {
            border: 0;
            padding-right: 1.5mm;
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
            width: 45mm;
        }

        .code-box {
            border: 0.7pt solid #000;
            height: 5.7mm;
            text-align: center;
            vertical-align: middle;
            width: 31mm;
        }

        .code-head {
            height: 5mm;
        }

        .main-heading {
            font-size: 13pt;
            font-weight: bold;
            margin: 13mm 80mm 3mm 0;
            text-align: center;
            text-transform: uppercase;
        }

        .party-block {
            margin-right: 84mm;
            padding-top: 1mm;
        }

        .party-table td {
            border: 0;
            padding: 1.2mm 0 0;
            vertical-align: bottom;
        }

        .party-label {
            font-size: 8.7pt;
            white-space: nowrap;
            width: 45mm;
        }

        .party-line {
            border-bottom: 0.7pt solid #000;
            min-height: 4mm;
            padding: 0 1mm 0.2mm;
        }

        .party-hint {
            font-size: 6.3pt;
            line-height: 1;
            padding-top: 0.4mm;
            text-align: center;
        }

        .period-line {
            font-size: 8.8pt;
            margin: 1mm 0 3mm;
            text-align: center;
        }

        .section-title {
            font-size: 10.5pt;
            font-weight: bold;
            margin: 2mm 0 1.5mm;
            text-align: center;
        }

        .official-table {
            table-layout: fixed;
        }

        .official-table th,
        .official-table td {
            border: 0.7pt solid #000;
            font-size: 7.7pt;
            font-weight: normal;
            padding: 1mm 1.1mm;
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

        .number-row td {
            height: 4.5mm;
            padding: 0.4mm;
            text-align: center;
        }

        .work-row td,
        .blank-row td {
            height: 8mm;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .signature-table {
            margin-top: 6mm;
        }

        .signature-table td {
            border: 0;
            font-size: 8.5pt;
            padding: 0;
            vertical-align: bottom;
        }

        .signature-line {
            border-bottom: 0.7pt solid #000;
            display: inline-block;
            min-width: 50mm;
            padding: 0 1mm 0.2mm;
            text-align: center;
        }

        .signature-hint {
            display: inline-block;
            font-size: 6.3pt;
            min-width: 50mm;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="top-area">
        <div class="top-note">
            <div>Типовая межотраслевая форма № КС-6</div>
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
                    <td class="code-box">0322002</td>
                </tr>
                <tr>
                    <td class="code-label">Дата составления</td>
                    <td class="code-box">{{ $formatDate($period_to ?? now()) }}</td>
                </tr>
                <tr>
                    <td class="code-label">по ОКПО</td>
                    <td class="code-box"></td>
                </tr>
                <tr>
                    <td class="code-label">по ОКПО</td>
                    <td class="code-box"></td>
                </tr>
                <tr>
                    <td class="code-label">Вид деятельности по ОКДП</td>
                    <td class="code-box"></td>
                </tr>
            </table>
        </div>

        <div class="main-heading">ОБЩИЙ ЖУРНАЛ РАБОТ № {{ $journalNumber }}</div>

        <div class="party-block">
            <table class="party-table">
                <tr>
                    <td class="party-label">Стройка</td>
                    <td>
                        <div class="party-line">{{ $projectLine }}</div>
                        <div class="party-hint">наименование, адрес</div>
                    </td>
                </tr>
                <tr>
                    <td class="party-label">Заказчик</td>
                    <td>
                        <div class="party-line">{{ $customerLine }}</div>
                        <div class="party-hint">организация, адрес, телефон, факс</div>
                    </td>
                </tr>
                <tr>
                    <td class="party-label">Подрядчик</td>
                    <td>
                        <div class="party-line">{{ $contractorLine }}</div>
                        <div class="party-hint">организация, адрес, телефон, факс</div>
                    </td>
                </tr>
                <tr>
                    <td class="party-label">Договор подряда</td>
                    <td>
                        <div class="party-line">{{ $contractLine }}</div>
                        <div class="party-hint">номер, дата</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="period-line">
        Период ведения: с {{ $formatDate($period_from ?? null) }} по {{ $formatDate($period_to ?? null) }}
    </div>

    <div class="section-title">Раздел 3. Сведения о выполнении работ в процессе строительства</div>

    <table class="official-table">
        <colgroup>
            <col style="width: 5%;">
            <col style="width: 9%;">
            <col style="width: 35%;">
            <col style="width: 17%;">
            <col style="width: 13%;">
            <col style="width: 13%;">
            <col style="width: 8%;">
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">№ записи</th>
                <th rowspan="2">Дата</th>
                <th rowspan="2">Наименование работ, место выполнения</th>
                <th rowspan="2">Объемы работ</th>
                <th rowspan="2">Условия производства работ</th>
                <th rowspan="2">Ответственный исполнитель</th>
                <th rowspan="2">Подпись</th>
            </tr>
            <tr></tr>
            <tr class="number-row">
                <td>1</td>
                <td>2</td>
                <td>3</td>
                <td>4</td>
                <td>5</td>
                <td>6</td>
                <td>7</td>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                @php
                    $volumes = collect($entry->workVolumes ?? [])->map(function ($volume): string {
                        $unit = $volume->measurementUnit?->short_name
                            ?? $volume->workType?->measurementUnit?->short_name
                            ?? $volume->estimateItem?->measurementUnit?->short_name
                            ?? '';
                        $name = $volume->workType?->name ?? $volume->estimateItem?->name ?? '';

                        return trim($volume->quantity . ' ' . $unit . ($name ? ' - ' . $name : ''));
                    })->filter()->implode('; ');
                    $weather = $entry->weather_conditions ?? [];
                    $weatherText = is_array($weather)
                        ? trim(($weather['temperature'] ?? '') . (($weather['temperature'] ?? null) !== null ? '°C' : '') . ' ' . ($weather['precipitation'] ?? ''))
                        : (string) $weather;
                @endphp
                <tr class="work-row">
                    <td class="text-center">{{ $entry->entry_number }}</td>
                    <td class="text-center">{{ $formatDate($entry->entry_date ?? null) }}</td>
                    <td>{{ $entry->work_description ?? '' }}</td>
                    <td>{{ $volumes }}</td>
                    <td>{{ $weatherText }}</td>
                    <td>{{ $entry->createdBy?->name ?? '' }}</td>
                    <td></td>
                </tr>
            @empty
                <tr class="work-row">
                    <td></td>
                    <td></td>
                    <td>Записи за указанный период отсутствуют</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endforelse

            @for($blank = $entries->count(); $blank < 4; $blank++)
                <tr class="blank-row">
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <table class="signature-table">
        <tr>
            <td style="width: 50%;">
                Ответственный за ведение журнала
                <span class="signature-line">{{ $journal->createdBy?->name ?? '' }}</span><br>
                <span style="display: inline-block; width: 49mm;"></span>
                <span class="signature-hint">должность, фамилия, инициалы</span>
            </td>
            <td class="text-right">
                <span class="signature-line"></span><br>
                <span class="signature-hint">подпись</span>
            </td>
        </tr>
    </table>
</body>
</html>
