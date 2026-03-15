<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 8mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #15202b;
            margin: 0;
            padding: 0;
        }

        .page {
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto;
        }

        .page-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 4mm;
            table-layout: fixed;
        }

        .page-grid td {
            vertical-align: top;
            width: 50%;
            padding: 0;
        }

        .label-card {
            border: 1px dashed #607d8b;
            border-radius: 3mm;
            height: {{ $layout['labelHeight'] }};
            padding: {{ $layout['labelPadding'] }};
            position: relative;
        }

        .label-title {
            font-size: {{ $layout['nameSize'] }};
            font-weight: 700;
            line-height: 1.35;
            min-height: 11mm;
        }

        .label-subtitle {
            margin-top: 2mm;
            font-size: {{ $layout['metaSize'] }};
            color: #455a64;
        }

        .label-subtitle strong {
            color: #102027;
        }

        .label-qr {
            text-align: center;
            margin: 5mm 0 4mm;
        }

        .label-qr img {
            width: {{ $layout['qrSize'] }};
            height: {{ $layout['qrSize'] }};
        }

        .label-code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: {{ $layout['codeSize'] }};
            text-align: center;
            word-break: break-all;
            color: #263238;
        }

        .label-hint {
            position: absolute;
            left: {{ $layout['labelPadding'] }};
            right: {{ $layout['labelPadding'] }};
            bottom: 5mm;
            font-size: 8px;
            color: #607d8b;
            text-align: center;
        }

        .empty-card {
            border: 1px dashed transparent;
            height: {{ $layout['labelHeight'] }};
        }
    </style>
</head>
<body>
@php
    $chunks = array_chunk($labels, $layout['itemsPerPage']);
@endphp

@foreach($chunks as $page)
    <div class="page">
        <table class="page-grid">
            @foreach(array_chunk($page, $layout['columns']) as $row)
                <tr>
                    @for($index = 0; $index < $layout['columns']; $index++)
                        <td>
                            @php $label = $row[$index] ?? null; @endphp
                            @if($label)
                                <div class="label-card">
                                    <div class="label-title">{{ $label['name'] }}</div>
                                    <div class="label-subtitle">
                                        <strong>Артикул:</strong> {{ $label['article'] }}
                                    </div>
                                    <div class="label-subtitle">
                                        <strong>Тип:</strong> {{ $label['asset_type'] }}@if(!empty($label['category'])) • {{ $label['category'] }}@endif
                                    </div>
                                    <div class="label-qr">
                                        <img src="{{ $label['qr_image'] }}" alt="QR {{ $label['name'] }}">
                                    </div>
                                    <div class="label-code">{{ $label['label_code'] }}</div>
                                    <div class="label-hint">{{ $layout['cutNote'] }}</div>
                                </div>
                            @else
                                <div class="empty-card"></div>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endforeach
        </table>
    </div>
@endforeach
</body>
</html>
