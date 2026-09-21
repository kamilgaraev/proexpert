@php
    $formatDate = static function (?string $date): string {
        if ($date === null || $date === '') {
            return '';
        }

        return \Illuminate\Support\Carbon::parse($date)->format('d.m.Y');
    };
    $isDraft = (bool) ($is_draft ?? false);
    $sectionI = $section_i ?? [];
    $sectionII = $section_ii ?? [];
    $totals = $totals ?? [];
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>М-29 № {{ $number ?? '' }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: "DejaVu Serif", serif; font-size: 9pt; color: #000; }
        h1 { font-size: 12pt; text-align: center; margin: 0 0 6px; }
        .meta { margin-bottom: 8px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 10px; }
        th, td { border: 1px solid #000; padding: 3px 4px; vertical-align: top; }
        th { font-weight: bold; text-align: center; }
        .note { font-size: 8pt; text-align: right; }
        .draft { color: #b00; font-weight: bold; text-align: center; }
        .section { font-weight: bold; margin: 8px 0 4px; }
    </style>
</head>
<body>
    <div class="note">Форма № М-29 · Утверждена ЦСУ СССР 24.11.1982 № 613</div>
    @if ($isDraft)
        <div class="draft">ЧЕРНОВИК</div>
    @endif
    <h1>Отчёт о расходе основных материалов в строительстве в сопоставлении с производственными нормами</h1>
    <div class="meta">
        Организация: {{ $organization['name'] ?? '' }}<br/>
        Объект: {{ $project['name'] ?? '' }}<br/>
        Прораб: {{ $foreman_name ?? '' }}<br/>
        Период: {{ $formatDate($period_start ?? null) }} — {{ $formatDate($period_end ?? null) }}<br/>
        Номер: {{ $number ?? '' }}
    </div>

    <div class="section">Раздел I. Нормативная потребность</div>
    <table>
        <thead>
            <tr>
                <th>Наименование работ</th>
                <th>Единица</th>
                <th>Объём</th>
                <th>Наименование материалов</th>
                <th>Норма на единицу</th>
                <th>Нормативная потребность</th>
                <th>Основание нормы</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($sectionI as $line)
            <tr>
                <td>{{ $line['work_name'] ?? '' }}</td>
                <td>{{ $line['work_unit'] ?? '' }}</td>
                <td>{{ $line['accepted_volume'] ?? '' }}</td>
                <td>{{ $line['material_name'] ?? '' }}</td>
                <td>{{ $line['rate_per_unit'] ?? '' }}</td>
                <td>{{ $line['normative_need'] ?? '' }}</td>
                <td>{{ $line['rate_basis_text'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="section">Раздел II. Сопоставление фактического расхода с производственными нормами</div>
    <table>
        <thead>
            <tr>
                <th>Наименование материалов</th>
                <th>Единица</th>
                <th>Расход по норме</th>
                <th>Фактический расход</th>
                <th>Экономия</th>
                <th>Перерасход</th>
                <th>Причины</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($sectionII as $line)
            <tr>
                <td>{{ $line['material_name'] ?? '' }}</td>
                <td>{{ $line['material_unit'] ?? '' }}</td>
                <td>{{ $line['normative_consumption'] ?? '' }}</td>
                <td>{{ $line['actual_consumption'] ?? '' }}</td>
                <td>{{ $line['economy'] ?? '0' }}</td>
                <td>{{ $line['overconsumption'] ?? '0' }}</td>
                <td>{{ $line['deviation_reason'] ?? '' }}</td>
            </tr>
        @endforeach
            <tr>
                <td><strong>Итого</strong></td>
                <td></td>
                <td>{{ $totals['normative_consumption'] ?? '0' }}</td>
                <td>{{ $totals['actual_consumption'] ?? '0' }}</td>
                <td>{{ $totals['economy'] ?? '0' }}</td>
                <td>{{ $totals['overconsumption'] ?? '0' }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
