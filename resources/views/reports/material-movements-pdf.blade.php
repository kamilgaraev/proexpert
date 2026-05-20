<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @include('pdf.partials.prohelper-brand-styles')
        body {
            font-family: 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif;
            font-size: 8px;
            line-height: 1.35;
            color: #263238;
            margin: 0;
            padding: 15px;
        }
        .header {
            margin-bottom: 14px;
            border-bottom: 2px solid #2f7d6f;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #2f7d6f;
        }
        .metadata {
            margin-top: 4px;
            color: #607d8b;
        }
        .totals-table {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px;
            background-color: #eef7f5;
            border: 1px solid #b8d8d2;
            text-align: center;
        }
        .total-val {
            font-size: 11px;
            font-weight: bold;
            color: #1f5f55;
        }
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .main-table th {
            background-color: #2f7d6f;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #25665b;
        }
        .main-table td {
            padding: 4px;
            border: 1px solid #c9e3df;
            vertical-align: top;
        }
        .numeric {
            text-align: right;
            white-space: nowrap;
        }
        .operation {
            font-weight: bold;
            white-space: nowrap;
        }
        .operation-receipt { color: #1b8f4d; }
        .operation-issue { color: #c0392b; }
        .operation-transfer { color: #2c6fb7; }
        .operation-write_off { color: #8e44ad; }
        .operation-adjustment { color: #b9770e; }
        .operation-return { color: #117a65; }
    </style>
</head>
<body>
    @include('pdf.partials.prohelper-brand-header')
    <div class="header">
        <div class="report-title">{{ $title }}</div>
        <div class="metadata">
            Период: {{ $period['date_from'] }} — {{ $period['date_to'] }} | Сформирован: {{ $generated_at }}
        </div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Операций: <div class="total-val">{{ $totals['total_movements'] }}</div></td>
            <td>Приходов: <div class="total-val">{{ $totals['receipt_count'] }}</div></td>
            <td>Расходов: <div class="total-val">{{ $totals['issue_count'] }}</div></td>
            <td>Перемещений: <div class="total-val">{{ $totals['transfer_count'] }}</div></td>
            <td>Сумма: <div class="total-val">{{ number_format($totals['total_amount'], 2, ',', ' ') }} ₽</div></td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th>Дата</th>
                <th>Операция</th>
                <th>Материал</th>
                <th>Код</th>
                <th>Склад</th>
                <th>Проект</th>
                <th>Кол-во</th>
                <th>Ед.</th>
                <th>Цена</th>
                <th>Сумма</th>
                <th>Документ</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data as $movement)
                <tr>
                    <td>{{ $movement['date'] ? \Carbon\Carbon::parse($movement['date'])->format('d.m.Y') : '-' }}</td>
                    <td class="operation operation-{{ $movement['type'] }}">{{ $movement['type'] }}</td>
                    <td>{{ $movement['material_name'] }}</td>
                    <td>{{ $movement['material_code'] }}</td>
                    <td>{{ $movement['warehouse'] }}</td>
                    <td>{{ $movement['project'] ?? '-' }}</td>
                    <td class="numeric">{{ number_format($movement['quantity'], 2, ',', ' ') }}</td>
                    <td>{{ $movement['unit'] }}</td>
                    <td class="numeric">{{ number_format($movement['price_per_unit'], 2, ',', ' ') }}</td>
                    <td class="numeric">{{ number_format($movement['total_amount'], 2, ',', ' ') }}</td>
                    <td>{{ $movement['document_number'] ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align: center;">За выбранный период движений материалов не найдено.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @include('pdf.partials.prohelper-brand-footer')
</body>
</html>
