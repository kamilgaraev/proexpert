<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $act->title ?? 'Акт выполненных работ' }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .info-section {
            margin-bottom: 20px;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        .info-label {
            font-weight: bold;
            min-width: 150px;
            display: inline-block;
        }
        .info-value {
            flex: 1;
        }
        .works-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .works-table th,
        .works-table td {
            border: 1px solid #333;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        .works-table th {
            background-color: #f5f5f5;
            font-weight: bold;
            text-align: center;
        }
        .works-table .number-col {
            width: 5%;
            text-align: center;
        }
        .works-table .work-name-col {
            width: 30%;
        }
        .works-table .unit-col {
            width: 10%;
            text-align: center;
        }
        .works-table .quantity-col {
            width: 10%;
            text-align: right;
        }
        .works-table .price-col {
            width: 15%;
            text-align: right;
        }
        .works-table .amount-col {
            width: 15%;
            text-align: right;
        }
        .works-table .date-col {
            width: 15%;
            text-align: center;
        }
        .total-row {
            font-weight: bold;
            background-color: #f0f0f0;
        }
        .materials-list {
            font-size: 10px;
            color: #666;
            margin-top: 4px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #333;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        .signature-block {
            width: 45%;
        }
        .signature-line {
            border-bottom: 1px solid #333;
            height: 30px;
            margin-bottom: 5px;
        }
        .generated-info {
            font-size: 10px;
            color: #666;
            text-align: right;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">АКТ ВЫПОЛНЕННЫХ РАБОТ</div>
        <div>№ {{ $act->act_document_number }} от {{ $act->act_date->format('d.m.Y') }}</div>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Организация:</span>
            <span class="info-value">{{ $contract->organization->name ?? 'Не указана' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Проект:</span>
            <span class="info-value">{{ $project->name ?? 'Не указан' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Подрядчик:</span>
            <span class="info-value">{{ $contractor->name ?? 'Не указан' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Договор:</span>
            <span class="info-value">№ {{ $contract->contract_number ?? 'Не указан' }} от {{ $contract->contract_date?->format('d.m.Y') ?? 'Не указана' }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Период выполнения:</span>
            <span class="info-value">{{ $contract->start_date?->format('d.m.Y') ?? 'Не указана' }} - {{ $contract->end_date?->format('d.m.Y') ?? 'Не указана' }}</span>
        </div>
    </div>

    <table class="works-table">
        <thead>
            <tr>
                <th class="number-col">№</th>
                <th class="work-name-col">Наименование работы</th>
                <th class="unit-col">Ед. изм.</th>
                <th class="quantity-col">Количество</th>
                <th class="price-col">Цена за ед.</th>
                <th class="amount-col">Сумма</th>
                <th class="date-col">Дата выполнения</th>
            </tr>
        </thead>
        <tbody>
            @if($works && $works->count() > 0)
                @foreach($works as $index => $work)
                    <tr>
                        <td class="number-col">{{ $index + 1 }}</td>
                        <td class="work-name-col">
                            {{ $work->workType->name ?? 'Не указано' }}
                            @if($work->materials && $work->materials->isNotEmpty())
                                <div class="materials-list">
                                    <strong>Материалы:</strong>
                                    @foreach($work->materials as $material)
                                        {{ $material->name ?? 'Не указан' }} ({{ $material->pivot->quantity ?? 0 }} {{ $material->unit ?? '' }}){{ !$loop->last ? ', ' : '' }}
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="unit-col">{{ $work->unit ?? '' }}</td>
                        <td class="quantity-col">{{ number_format($work->quantity ?? 0, 2, ',', ' ') }}</td>
                        <td class="price-col">{{ number_format($work->unit_price ?? 0, 2, ',', ' ') }} ₽</td>
                        <td class="amount-col">{{ number_format($work->total_amount ?? 0, 2, ',', ' ') }} ₽</td>
                        <td class="date-col">{{ $work->completion_date?->format('d.m.Y') ?? 'Не указана' }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="7" style="text-align: center; padding: 20px; font-style: italic; color: #666;">
                        Нет выполненных работ
                    </td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align: right; font-weight: bold;">ИТОГО:</td>
                <td class="amount-col">{{ number_format($total_amount, 2, ',', ' ') }} ₽</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer">
        <div>
            <strong>Всего выполнено работ на сумму:</strong> {{ number_format($total_amount, 2, ',', ' ') }} рублей
        </div>
        
        @if($act->description)
            <div style="margin-top: 15px;">
                <strong>Примечания:</strong> {{ $act->description }}
            </div>
        @endif

        <div class="signatures">
            <div class="signature-block">
                <div>Представитель заказчика:</div>
                <div class="signature-line"></div>
                <div style="text-align: center; font-size: 10px;">(подпись, ФИО)</div>
            </div>
            <div class="signature-block">
                <div>Представитель подрядчика:</div>
                <div class="signature-line"></div>
                <div style="text-align: center; font-size: 10px;">(подпись, ФИО)</div>
            </div>
        </div>
    </div>

    <div class="generated-info">
        Отчет сформирован: {{ $generated_at }}
    </div>
</body>
</html> 