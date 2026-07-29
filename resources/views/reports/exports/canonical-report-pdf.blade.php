<!doctype html>
<html lang="{{ str_starts_with($document->metadata['locale'], 'ru') ? 'ru' : 'en' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 12mm; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { font-size: 15px; margin: 0 0 8px; }
        .identity { color: #4b5563; margin-bottom: 12px; }
        .identity div { margin: 2px 0; overflow-wrap: anywhere; }
        table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 4px; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background: #e8eef7; font-weight: bold; }
        tfoot td { background: #f3f6fa; font-weight: bold; }
    </style>
</head>
<body>
<h1>{{ $document->metadata['report_code'] }}</h1>
<div class="identity">
    <div>{{ $document->metadata['run_id'] }}</div>
    <div>{{ $document->metadata['snapshot']['id'] }}</div>
    <div>{{ $document->metadata['definition_hash'] }}</div>
    <div>{{ $document->metadata['query_hash'] }}</div>
    <div>{{ $document->metadata['source_hash'] }}</div>
    <div>{{ $document->metadata['result_hash'] }}</div>
    @if($document->metadata['snapshot']['seal'] !== null)
        <div>{{ $document->metadata['snapshot']['seal']['key_id'] }}</div>
        <div>{{ $document->metadata['snapshot']['seal']['algorithm'] }}</div>
        <div>{{ $document->metadata['snapshot']['seal']['sealed_payload_hash'] }}</div>
        <div>{{ $document->metadata['snapshot']['seal']['sealed_at'] }}</div>
    @endif
</div>
<table>
    <thead>
    <tr>
        @foreach($document->headers as $header)
            <th>{{ $header['label'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @foreach($document->rows as $row)
        <tr>
            @foreach($row as $cell)
                <td>{{ $cell }}</td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
    @if($document->totals !== [])
        <tfoot>
        <tr>
            @foreach($document->headers as $index => $header)
                <td>{{ $document->totals[$header['id']] ?? ($index === 0 ? (str_starts_with($document->metadata['locale'], 'ru') ? 'Итого' : 'Total') : '') }}</td>
            @endforeach
        </tr>
        </tfoot>
    @endif
</table>
</body>
</html>
