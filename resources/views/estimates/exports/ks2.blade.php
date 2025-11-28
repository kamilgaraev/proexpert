<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>КС-2 № {{ $act->number }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 14pt;
            font-weight: bold;
        }
        .info {
            margin-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .signatures {
            margin-top: 30px;
        }
        .signature-block {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Унифицированная форма № КС-2</div>
        <div class="title">АКТ № {{ $act->act_document_number ?? $act->id }} от {{ $act->act_date->format('d.m.Y') }}</div>
        <div>о приемке выполненных работ</div>
    </div>

    <div class="info">
        <p><strong>Заказчик:</strong> {{ $contract->project->organization->name ?? $contract->organization->name ?? '' }}</p>
        <p><strong>Подрядчик:</strong> {{ $contract->contractor->name ?? '' }}</p>
        <p><strong>Договор:</strong> № {{ $contract->number }} от {{ $contract->date->format('d.m.Y') }}</p>
        <p><strong>Объект:</strong> {{ $contract->project->name ?? '' }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">№ п/п</th>
                <th style="width: 35%;">Наименование работ</th>
                <th style="width: 10%;">Номер расценки</th>
                <th style="width: 8%;">Ед. изм.</th>
                <th style="width: 10%;">Количество</th>
                <th style="width: 12%;">Цена за ед., руб.</th>
                <th style="width: 12%;">Стоимость, руб.</th>
                <th style="width: 8%;">Примечание</th>
            </tr>
        </thead>
        <tbody>
            @foreach($works as $index => $work)
            @php
                // Используем данные из pivot таблицы для акта (included_quantity, included_amount)
                // или данные самой работы, если pivot нет
                $includedQuantity = $work->pivot->included_quantity ?? $work->quantity ?? 0;
                $includedAmount = $work->pivot->included_amount ?? $work->total_amount ?? 0;
                $unitPrice = $includedQuantity > 0 ? ($includedAmount / $includedQuantity) : ($work->price ?? 0);
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $work->workType->name ?? $work->description ?? '' }}</td>
                <td class="text-center">{{ $work->workType->code ?? '' }}</td>
                <td class="text-center">{{ $work->workType->measurementUnit->short_name ?? '' }}</td>
                <td class="text-right">{{ number_format($includedQuantity, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($unitPrice, 2, ',', ' ') }}</td>
                <td class="text-right">{{ number_format($includedAmount, 2, ',', ' ') }}</td>
                <td>{{ $work->pivot->notes ?? $work->notes ?? '' }}</td>
            </tr>
            @endforeach
            <tr>
                <td colspan="6" class="text-right"><strong>ИТОГО:</strong></td>
                <td class="text-right"><strong>{{ number_format($total_amount, 2, ',', ' ') }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="signatures">
        <div class="signature-block">
            <p><strong>Заказчик:</strong></p>
            <p>_____________ / _______________ / &nbsp;&nbsp;&nbsp; "___" _________ {{ date('Y') }} г.</p>
        </div>
        
        <div class="signature-block">
            <p><strong>Подрядчик:</strong></p>
            <p>_____________ / _______________ / &nbsp;&nbsp;&nbsp; "___" _________ {{ date('Y') }} г.</p>
        </div>
    </div>
</body>
</html>

