<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] ?? 'Отчет ProHelper' }}</title>
    <style>
        @include('pdf.partials.prohelper-brand-styles')

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 11px;
            line-height: 1.45;
            margin: 24px;
        }

        .report-title {
            margin: 18px 0 6px;
            color: #111827;
            font-size: 24px;
            font-weight: 700;
        }

        .report-description {
            margin: 0 0 14px;
            color: #4b5563;
            font-size: 12px;
        }

        .meta-table,
        .summary-table,
        .section-table,
        .status-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table {
            margin: 12px 0 16px;
            background: #f8fafc;
            border: 1px solid #dbe5f1;
        }

        .meta-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e5edf6;
        }

        .meta-label {
            width: 160px;
            color: #64748b;
            font-weight: 700;
        }

        .summary-table {
            margin: 14px 0 18px;
        }

        .summary-table td {
            width: 25%;
            padding: 9px;
            border: 1px solid #dbe5f1;
            background: #f8fbff;
            vertical-align: top;
        }

        .summary-value {
            color: #2563eb;
            font-size: 18px;
            font-weight: 700;
        }

        .summary-label {
            margin-top: 3px;
            color: #334155;
            font-weight: 700;
        }

        .summary-hint {
            color: #64748b;
            font-size: 10px;
        }

        .section {
            margin-top: 18px;
            page-break-inside: avoid;
        }

        .section-header {
            padding: 9px 10px;
            background: #eef5ff;
            border: 1px solid #cfe0f6;
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
        }

        .section-meta {
            margin: 6px 0 8px;
            color: #475569;
        }

        .status-table {
            margin: 6px 0 10px;
        }

        .status-table td {
            padding: 5px 7px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .section-table th {
            padding: 7px;
            background: #1d4ed8;
            color: #ffffff;
            text-align: left;
            font-weight: 700;
        }

        .section-table td {
            padding: 7px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .section-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .empty-state {
            padding: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
        }
    </style>
</head>
<body>
    @include('pdf.partials.prohelper-brand-header')

    <h1 class="report-title">{{ $report['title'] ?? 'Отчет ProHelper' }}</h1>
    <p class="report-description">{{ $report['description'] ?? '' }}</p>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Организация</td>
            <td>{{ $report['organization_name'] ?? 'Организация' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Период</td>
            <td>{{ $report['period_label'] ?? 'весь доступный период' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Сформировано</td>
            <td>{{ $report['generated_at'] ?? '' }} в ProHelper</td>
        </tr>
        @if(!empty($report['generated_by']))
            <tr>
                <td class="meta-label">Пользователь</td>
                <td>{{ $report['generated_by'] }}</td>
            </tr>
        @endif
    </table>

    @if(!empty($report['summary_cards']))
        <table class="summary-table">
            @foreach(array_chunk($report['summary_cards'], 4) as $row)
                <tr>
                    @foreach($row as $card)
                        <td>
                            <div class="summary-value">{{ $card['value'] ?? '0' }}</div>
                            <div class="summary-label">{{ $card['label'] ?? '' }}</div>
                            <div class="summary-hint">{{ $card['hint'] ?? '' }}</div>
                        </td>
                    @endforeach
                    @for($i = count($row); $i < 4; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    @foreach(($report['sections'] ?? []) as $section)
        <div class="section">
            <div class="section-header">{{ $section['title'] ?? 'Раздел отчета' }}</div>
            <div class="section-meta">
                Записей: {{ $section['total'] ?? 0 }}
                @if(!empty($section['amount_value']))
                    · {{ $section['amount_label'] ?? 'Сумма' }}: {{ $section['amount_value'] }}
                @endif
            </div>

            @if(!empty($section['status_breakdown']))
                <table class="status-table">
                    <tr>
                        @foreach($section['status_breakdown'] as $status)
                            <td>{{ $status['label'] ?? 'Статус' }}: <strong>{{ $status['count'] ?? 0 }}</strong></td>
                        @endforeach
                    </tr>
                </table>
            @endif

            @if(!empty($section['rows']) && !empty($section['headers']))
                <table class="section-table">
                    <thead>
                        <tr>
                            @foreach($section['headers'] as $header)
                                <th>{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($section['rows'] as $row)
                            <tr>
                                @foreach($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="empty-state">По этому разделу нет данных для выбранного периода.</div>
            @endif
        </div>
    @endforeach

    @include('pdf.partials.prohelper-brand-footer')
</body>
</html>
