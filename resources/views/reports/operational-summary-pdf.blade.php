@php
    $sections = array_values(is_array($report['sections'] ?? null) ? $report['sections'] : []);
    $keyFindings = array_values(is_array($report['key_findings'] ?? null) ? $report['key_findings'] : []);
    $summaryCards = array_values(is_array($report['summary_cards'] ?? null) ? $report['summary_cards'] : []);
    $ragReport = is_array($report['rag_report'] ?? null) ? $report['rag_report'] : null;
    $ragSections = is_array($ragReport['sections'] ?? null) ? array_values($ragReport['sections']) : [];
    $ragRisks = is_array($ragReport['risks'] ?? null) ? array_values($ragReport['risks']) : [];
    $ragNextActions = is_array($ragReport['next_actions'] ?? null) ? array_values($ragReport['next_actions']) : [];
    $sources = array_values(is_array($report['sources'] ?? null) ? $report['sources'] : []);
    $limitations = array_values(is_array($report['limitations'] ?? null) ? $report['limitations'] : []);
    $description = is_string($report['description'] ?? null) ? trim($report['description']) : '';
    $hasStructuredData = (bool) ($report['has_structured_data'] ?? false);

    if (!$hasStructuredData) {
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            if ((int) ($section['total'] ?? 0) > 0 || (is_array($section['rows'] ?? null) && $section['rows'] !== [])) {
                $hasStructuredData = true;
                break;
            }
        }
    }

    if ($keyFindings === [] && $description !== '') {
        $keyFindings[] = $description;
    }

    $showRag = $ragReport !== null && ((is_string($ragReport['summary'] ?? null) && trim((string) $ragReport['summary']) !== '') || $ragSections !== []);
    $showRagAsPrimary = $showRag && (($report['rag_context_mode'] ?? null) === 'primary' || !$hasStructuredData);
    $showStructuredSections = $sections !== [] && ($hasStructuredData || !$showRagAsPrimary);
    $sourcesAlreadyDetailed = $ragSections !== [];
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] ?? 'Отчет МОСТ' }}</title>
    <style>
        @@page {
            margin: 18mm 16mm 20mm 16mm;
        }

        @include('pdf.partials.most-brand-styles')

        body {
            margin: 0;
            font-family: "DejaVu Sans", DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 10.5px;
            line-height: 1.5;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
        }

        p {
            margin: 0 0 7px 0;
        }

        .report-title {
            margin: 14px 0 4px 0;
            color: #0f172a;
            font-size: 22px;
            line-height: 1.2;
            font-weight: 700;
        }

        .report-description {
            margin: 0 0 12px 0;
            color: #475569;
            font-size: 11px;
        }

        .meta-table {
            margin: 10px 0 14px 0;
            border: 1px solid #d7dee8;
            background: #f8fafc;
        }

        .meta-table td {
            padding: 7px 9px;
            border-bottom: 1px solid #e5eaf1;
            vertical-align: top;
        }

        .meta-table tr:last-child td {
            border-bottom: none;
        }

        .meta-label {
            width: 145px;
            color: #64748b;
            font-weight: 700;
        }

        .block {
            margin-top: 14px;
            page-break-inside: avoid;
        }

        .block-title {
            margin-bottom: 7px;
            padding-bottom: 4px;
            border-bottom: 1px solid #d7dee8;
            color: #0f172a;
            font-size: 13px;
            font-weight: 700;
        }

        .key-findings {
            padding: 10px 12px;
            border: 1px solid #d7dee8;
            border-left: 3px solid #2563eb;
            background: #f8fafc;
        }

        .key-findings .block-title {
            margin-bottom: 5px;
            border-bottom: none;
            padding-bottom: 0;
        }

        .narrative-list {
            margin: 5px 0 0 17px;
            padding: 0;
        }

        .narrative-list li {
            margin-bottom: 4px;
        }

        .metrics-table {
            margin-top: 5px;
        }

        .metrics-table td {
            width: 25%;
            padding: 8px 9px;
            border: 1px solid #d7dee8;
            background: #ffffff;
            vertical-align: top;
        }

        .metric-value {
            color: #1d4ed8;
            font-size: 17px;
            line-height: 1.15;
            font-weight: 700;
        }

        .metric-label {
            margin-top: 3px;
            color: #1f2937;
            font-weight: 700;
        }

        .metric-hint {
            margin-top: 1px;
            color: #64748b;
            font-size: 8.5px;
        }

        .section {
            margin-top: 13px;
            page-break-inside: avoid;
        }

        .section-header {
            padding: 8px 9px;
            border: 1px solid #cbd5e1;
            background: #eef2f7;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
        }

        .section-meta {
            margin: 5px 0 7px 0;
            color: #475569;
            font-size: 9.5px;
        }

        .status-table {
            margin: 5px 0 8px 0;
        }

        .status-table td {
            padding: 5px 6px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
        }

        .section-table th {
            padding: 6px 7px;
            border: 1px solid #334155;
            background: #334155;
            color: #ffffff;
            text-align: left;
            font-weight: 700;
        }

        .section-table td {
            padding: 6px 7px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .section-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .empty-state {
            padding: 9px 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #64748b;
        }

        .rag-block {
            padding: 10px 12px;
            border: 1px solid #d7dee8;
            background: #ffffff;
        }

        .rag-primary {
            padding: 12px 13px;
            border-left: 3px solid #2563eb;
            background: #f8fafc;
        }

        .rag-summary {
            margin-bottom: 8px;
            color: #334155;
        }

        .source-block {
            margin-top: 8px;
            padding: 8px 9px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .source-heading {
            color: #111827;
            font-size: 11px;
            font-weight: 700;
        }

        .source-meta {
            margin-top: 2px;
            color: #64748b;
            font-size: 8.5px;
        }

        .source-fact,
        .source-excerpt {
            margin-top: 5px;
            color: #334155;
        }

        .compact-source {
            padding: 6px 0;
            border-bottom: 1px solid #e5eaf1;
            page-break-inside: avoid;
        }

        .compact-source:last-child {
            border-bottom: none;
        }

        .compact-source-title {
            color: #111827;
            font-weight: 700;
        }
    </style>
</head>
<body>
    @include('pdf.partials.most-brand-header', ['generated_at' => $report['generated_at'] ?? null])

    <h1 class="report-title">{{ $report['title'] ?? 'Отчет МОСТ' }}</h1>
    @if($description !== '')
        <p class="report-description">{{ $description }}</p>
    @endif

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
            <td>{{ $report['generated_at'] ?? '' }} в МОСТ</td>
        </tr>
        @if(!empty($report['generated_by']))
            <tr>
                <td class="meta-label">Пользователь</td>
                <td>{{ $report['generated_by'] }}</td>
            </tr>
        @endif
    </table>

    @if($keyFindings !== [])
        <div class="block key-findings">
            <div class="block-title">Ключевые выводы</div>
            <ul class="narrative-list">
                @foreach($keyFindings as $finding)
                    @if(is_scalar($finding) && trim((string) $finding) !== '')
                        <li>{{ $finding }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if($summaryCards !== [])
        <div class="block">
            <div class="block-title">Метрики</div>
            <table class="metrics-table">
                @foreach(array_chunk($summaryCards, 4) as $row)
                    <tr>
                        @foreach($row as $card)
                            <td>
                                <div class="metric-value">{{ $card['value'] ?? '0' }}</div>
                                <div class="metric-label">{{ $card['label'] ?? '' }}</div>
                                @if(!empty($card['hint']))
                                    <div class="metric-hint">{{ $card['hint'] }}</div>
                                @endif
                            </td>
                        @endforeach
                        @for($i = count($row); $i < 4; $i++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if($showRagAsPrimary)
        @include('reports.partials.rag-context-pdf', [
            'ragReport' => $ragReport,
            'ragSections' => $ragSections,
            'ragMode' => 'primary',
            'ragBlockTitle' => 'Ключевой контекст из базы знаний',
        ])
    @endif

    @if($showStructuredSections)
        <div class="block">
            <div class="block-title">Основные разделы</div>

            @foreach($sections as $section)
                @if(is_array($section))
                    <div class="section">
                        <div class="section-header">{{ $section['title'] ?? 'Раздел отчета' }}</div>
                        <div class="section-meta">
                            Записей: {{ $section['total'] ?? 0 }}
                            @if(!empty($section['amount_value']))
                                · {{ $section['amount_label'] ?? 'Сумма' }}: {{ $section['amount_value'] }}
                            @endif
                        </div>

                        @if(!empty($section['status_breakdown']) && is_array($section['status_breakdown']))
                            <table class="status-table">
                                <tr>
                                    @foreach($section['status_breakdown'] as $status)
                                        @if(is_array($status))
                                            <td>{{ $status['label'] ?? 'Статус' }}: <strong>{{ $status['count'] ?? 0 }}</strong></td>
                                        @endif
                                    @endforeach
                                </tr>
                            </table>
                        @endif

                        @if(!empty($section['rows']) && !empty($section['headers']) && is_array($section['rows']) && is_array($section['headers']))
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
                                        @if(is_array($row))
                                            <tr>
                                                @foreach($row as $cell)
                                                    <td>{{ $cell }}</td>
                                                @endforeach
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="empty-state">По этому разделу нет данных для выбранного периода.</div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if($showRag && !$showRagAsPrimary)
        @include('reports.partials.rag-context-pdf', [
            'ragReport' => $ragReport,
            'ragSections' => $ragSections,
            'ragMode' => 'supporting',
            'ragBlockTitle' => 'Контекст из базы знаний',
        ])
    @endif

    @if($ragRisks !== [])
        <div class="block">
            <div class="block-title">Риски</div>
            <ul class="narrative-list">
                @foreach($ragRisks as $risk)
                    @if(is_scalar($risk) && trim((string) $risk) !== '')
                        <li>{{ $risk }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if($ragNextActions !== [])
        <div class="block">
            <div class="block-title">Ближайшие действия</div>
            <ul class="narrative-list">
                @foreach($ragNextActions as $action)
                    @if(is_scalar($action) && trim((string) $action) !== '')
                        <li>{{ $action }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @if($sources !== [])
        <div class="block">
            <div class="block-title">Источники</div>
            @foreach($sources as $source)
                @if(is_array($source))
                    @php
                        $sourceTitle = (string) ($source['display_title'] ?? $source['title'] ?? 'Источник базы знаний');
                        $sourceMeta = array_values(is_array($source['meta'] ?? null) ? $source['meta'] : []);
                        $sourceExcerpt = (string) ($source['reference_excerpt'] ?? $source['display_excerpt'] ?? $source['excerpt'] ?? '');
                        if (mb_strlen($sourceExcerpt) > 220) {
                            $sourceExcerpt = rtrim(mb_substr($sourceExcerpt, 0, 217)).'...';
                        }
                    @endphp

                    <div class="compact-source">
                        <span class="compact-source-title">{{ $loop->iteration }}. {{ $sourceTitle }}</span>
                        @if($sourceMeta !== [])
                            <div class="source-meta">{{ implode(' · ', $sourceMeta) }}</div>
                        @endif
                        @if(!$sourcesAlreadyDetailed && trim($sourceExcerpt) !== '')
                            <div class="source-excerpt">{{ $sourceExcerpt }}</div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if($limitations !== [])
        <div class="block">
            <div class="block-title">Ограничения данных</div>
            <ul class="narrative-list">
                @foreach($limitations as $limitation)
                    @if(is_scalar($limitation) && trim((string) $limitation) !== '')
                        <li>{{ $limitation }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    @include('pdf.partials.most-brand-footer', ['generated_at' => $report['generated_at'] ?? null])
</body>
</html>
