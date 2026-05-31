<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <title>Отчет по актам выполненных работ</title>
    <style>
        @include('pdf.partials.prohelper-brand-styles')
        body {
            font-family: 'DejaVu Sans', 'Arial Unicode MS', Arial, sans-serif;
            font-size: 8px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 15px;
        }
        .header {
            margin-bottom: 14px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 8px;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #1d4ed8;
        }
        .metadata {
            color: #64748b;
            margin-top: 3px;
        }
        .totals-table,
        .summary-table,
        .main-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table {
            margin-bottom: 14px;
        }
        .totals-table td {
            padding: 5px;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            text-align: center;
        }
        .total-val {
            font-size: 11px;
            font-weight: bold;
            color: #1d4ed8;
        }
        .section-title {
            font-size: 10px;
            font-weight: bold;
            margin: 12px 0 5px;
            color: #0f172a;
        }
        .summary-table th,
        .main-table th {
            background-color: #2563eb;
            color: white;
            padding: 5px;
            text-align: left;
            border: 1px solid #1d4ed8;
        }
        .summary-table td,
        .main-table td {
            padding: 4px;
            border: 1px solid #cbd5e1;
        }
        .numeric {
            text-align: right;
        }
    </style>
</head>
<body>
    @include('pdf.partials.prohelper-brand-header')
    <div class="header">
        <div class="report-title">Отчет по актам выполненных работ</div>
        <div class="metadata">Сформирован: {{ $generated_at }}</div>
    </div>

    <table class="totals-table">
        <tr>
            <td>Всего актов<div class="total-val">{{ $totals['total_acts'] }}</div></td>
            <td>Утверждено<div class="total-val">{{ $totals['approved_acts'] }}</div></td>
            <td>На согласовании<div class="total-val">{{ $totals['pending_acts'] }}</div></td>
            <td>Общая сумма<div class="total-val">{{ number_format($totals['total_amount'], 2, ',', ' ') }} ₽</div></td>
            <td>Утверждено на сумму<div class="total-val">{{ number_format($totals['approved_amount'], 2, ',', ' ') }} ₽</div></td>
        </tr>
    </table>

    <div class="section-title">Разрез по статусам</div>
    <table class="summary-table">
        <thead>
            <tr>
                <th>Статус</th>
                <th class="numeric">Актов</th>
                <th class="numeric">Сумма</th>
                <th class="numeric">Утверждено на сумму</th>
            </tr>
        </thead>
        <tbody>
            @foreach($by_status as $status)
                <tr>
                    <td>{{ $status['status_label'] }}</td>
                    <td class="numeric">{{ $status['acts_count'] }}</td>
                    <td class="numeric">{{ number_format($status['total_amount'], 2, ',', ' ') }} ₽</td>
                    <td class="numeric">{{ number_format($status['approved_amount'], 2, ',', ' ') }} ₽</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Крупнейшие объекты</div>
    <table class="summary-table">
        <thead>
            <tr>
                <th>Объект</th>
                <th class="numeric">Актов</th>
                <th class="numeric">Утверждено</th>
                <th class="numeric">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($by_projects as $project)
                <tr>
                    <td>{{ $project['project'] }}</td>
                    <td class="numeric">{{ $project['acts_count'] }}</td>
                    <td class="numeric">{{ $project['approved_acts'] }}</td>
                    <td class="numeric">{{ number_format($project['total_amount'], 2, ',', ' ') }} ₽</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Детализация</div>
    <table class="main-table">
        <thead>
            <tr>
                <th>Номер</th>
                <th>Дата</th>
                <th>Договор</th>
                <th>Объект</th>
                <th>Подрядчик</th>
                <th>Статус</th>
                <th class="numeric">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
                <tr>
                    <td>{{ $row['act_document_number'] }}</td>
                    <td>{{ $row['act_date'] }}</td>
                    <td>{{ $row['contract_number'] }}</td>
                    <td>{{ $row['project'] }}</td>
                    <td>{{ $row['contractor'] }}</td>
                    <td>{{ $row['status_label'] }}</td>
                    <td class="numeric">{{ number_format($row['amount'], 2, ',', ' ') }} ₽</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @include('pdf.partials.prohelper-brand-footer')
</body>
</html>
