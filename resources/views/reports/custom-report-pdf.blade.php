<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>{{ $report['title'] }}</title>
<style>
body { font-family: 'DejaVu Sans', sans-serif; color: #203747; font-size: 9px; }
h1 { font-size: 21px; } h2 { font-size: 15px; margin-top: 24px; }
.meta { color: #52616b; margin-bottom: 10px; } table { border-collapse: collapse; width: 100%; table-layout: fixed; }
thead { display: table-header-group; } th { background: #eaf0f3; text-align: left; }
th, td { padding: 6px; border: 1px solid #ced8df; overflow-wrap: break-word; vertical-align: top; }
tr { page-break-inside: avoid; } .footer { position: fixed; bottom: -15px; font-size: 8px; color: #637683; }
</style></head>
<body>
<h1>{{ $report['title'] }}</h1>
<div class="meta">{{ trans_message('ai_custom_reports.as_of') }}: {{ $report['generated_at'] }}</div>
@foreach($report['sections'] as $section)
<h2>{{ $section['title'] }}</h2>
<div class="meta">{{ trans_message('ai_custom_reports.source_count') }}: {{ $section['source_row_count'] }}</div>
<div class="meta">{{ $section['filter_summary'] ?? '' }}</div>
<table><thead><tr>@foreach($section['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
<tbody>@forelse($section['rows'] as $row)<tr>@foreach($row as $value)<td>{{ $value === null ? '—' : $value }}</td>@endforeach</tr>
@empty<tr><td colspan="{{ count($section['headers']) }}">{{ trans_message('ai_custom_reports.no_rows') }}</td></tr>@endforelse</tbody></table>
@endforeach
<div class="footer">МОСТ · {{ $report['title'] }}</div>
</body></html>
