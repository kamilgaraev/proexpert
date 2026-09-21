<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Общий журнал № {{ $header['journal_number'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 14mm 10mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
        h1 { text-align: center; font-size: 14pt; margin: 0 0 5mm; }
        h2 { font-size: 10pt; text-align: center; margin: 7mm 0 3mm; page-break-after: avoid; }
        h3 { font-size: 9pt; margin: 4mm 0 2mm; page-break-after: avoid; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: .4pt solid #333; padding: 1.4mm; vertical-align: top; overflow-wrap: anywhere; word-wrap: break-word; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .meta td:first-child { width: 33%; font-weight: bold; }
        .representative td { min-height: 8mm; }
        .signature { min-width: 24mm; border-bottom: .5pt solid #111; display: inline-block; height: 4mm; }
        .muted { color: #555; font-size: 7pt; }
        .section { page-break-before: always; }
        .section:first-of-type { page-break-before: avoid; }
        .page-number:after { content: counter(page); }
        .footer { position: fixed; left: 0; right: 0; bottom: -9mm; height: 6mm; text-align: center; font-size: 7pt; color: #555; }
    </style>
</head>
<body>
    <h1>Общий журнал, в котором ведется учет выполнения работ<br>по строительству, реконструкции, капитальному ремонту объекта капитального строительства</h1>
    <table class="meta">
        @foreach($header as $key => $value)
            <tr><td>{{ $definition::headerFields()[$key] }}</td><td>{{ $value ?? '' }}</td></tr>
        @endforeach
    </table>

    <h2>Уполномоченные представители</h2>
    @foreach($definition::representativeGroups() as $group => $label)
        <h3>{{ $label }}</h3>
        <table class="representative"><thead><tr><th>№ п/п</th><th>Фамилия, имя, отчество</th><th>Должность</th><th>Наименование, дата, номер документа, подтверждающего полномочие</th><th>Идентификационный номер в НРС</th><th>Подпись</th></tr></thead><tbody>
        @forelse((array) ($representatives[$group] ?? []) as $index => $representative)
            <tr><td>{{ $index + 1 }}</td><td>{{ $representative['name'] ?? '' }}</td><td>{{ $representative['position'] ?? '' }}</td><td>{{ $representative['authority'] ?? '' }}</td><td>{{ $representative['nrs_number'] ?? '' }}</td><td><span class="signature"></span></td></tr>
        @empty
            <tr><td>1</td><td></td><td></td><td></td><td></td><td><span class="signature"></span></td></tr>
        @endforelse
        </tbody></table>
    @endforeach

    <h2>Сведения об изменениях в записях титульного листа</h2>
    <table><thead><tr><th>№ п/п</th><th>Дата</th><th>Изменения в записях с указанием основания</th><th>Фамилия, инициалы, должность лица, внесшего изменения, реквизиты полномочия</th><th>Подпись</th></tr></thead><tbody>
    @forelse($titleChanges as $index => $change)
        <tr><td>{{ $index + 1 }}</td><td>{{ $change['date'] ?? '' }}</td><td>{{ $change['change'] ?? '' }}</td><td>{{ $change['representative'] ?? '' }} {{ $change['authority'] ?? '' }}</td><td><span class="signature"></span></td></tr>
    @empty
        <tr><td>1</td><td></td><td></td><td></td><td><span class="signature"></span></td></tr>
    @endforelse
    </tbody></table>

    @foreach($definition::sections() as $number => $section)
        <section class="section">
            <h2>РАЗДЕЛ {{ $number }}<br>{{ $section['title'] }}</h2>
            <table><thead><tr><th style="width: {{ $section['widths'][0] }}%;">№ п/п</th>@foreach($section['columns'] as $label)<th style="width: {{ $section['widths'][$loop->index + 1] }}%;">{{ $label }}</th>@endforeach</tr></thead><tbody>
            @forelse($sections[$number] as $index => $row)
                <tr><td style="width: {{ $section['widths'][0] }}%;">{{ $row['_number'] ?? ($index + 1) }}@if(!empty($row['_continuation']))<br><span class="muted">(продолжение)</span>@endif</td>@foreach($section['columns'] as $key => $_label)<td style="width: {{ $section['widths'][$loop->index + 1] }}%;">{{ $row[$key] ?? '' }}</td>@endforeach</tr>
            @empty
                <tr><td style="width: {{ $section['widths'][0] }}%;">1</td>@foreach($section['columns'] as $_key => $_label)<td style="width: {{ $section['widths'][$loop->index + 1] }}%;"></td>@endforeach</tr>
            @endforelse
            </tbody></table>
        </section>
    @endforeach

    <p class="muted">Проект для оформления бумажного журнала. Ревизия снимка: {{ $revision }}@if($correctionReason), причина исправления: {{ $correctionReason }}@endif</p>
    <div class="footer">Проект для оформления бумажного журнала · страница <span class="page-number"></span></div>
</body>
</html>
