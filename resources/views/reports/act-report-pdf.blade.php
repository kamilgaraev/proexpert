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
            color: #000;
            margin: 0;
            padding: 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .act-number {
            font-size: 14px;
            margin-bottom: 20px;
        }
        .parties-section {
            margin-bottom: 25px;
            line-height: 1.6;
        }
        .party-info {
            margin-bottom: 15px;
        }
        .description-section {
            margin: 25px 0;
            text-align: justify;
            line-height: 1.6;
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
        <div class="title">АКТ № {{ $act->act_document_number }} от «{{ $act->act_date->format('d') }}» 
        @php
            $months = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
            echo $months[(int)$act->act_date->format('n')];
        @endphp 
        {{ $act->act_date->format('Y') }} г.</div>
    </div>

    <div class="parties-section">
        <div class="party-info">
            <strong>Исполнитель:</strong> {{ $contractor->name ?? 'ООО «АЛП СТРОЙК»' }}, в лице директора {{ $contractor->director_name ?? 'Нуртдинова Х.Х.' }}, действующего на основании Устава. {{ $contractor->details ?? 'ИНН 1603085641, КПП 160301001, р/с 40702810055000129602 в КБ ЛОКО-Банк г. Казань, к/с 30101810000000000797, БИК 049205797' }}
        </div>
        
        <div class="party-info">
            <strong>Заказчик:</strong> {{ $contract->organization->name ?? 'Не указан' }}, в лице {{ $contract->organization->representative_name ?? 'представителя' }}, действующего на основании {{ $contract->organization->authority_basis ?? 'Устава' }}.
        </div>
    </div>

    @if($act->description)
    <div class="description-section">
        {{ $act->description }}
    </div>
    @endif

    <table class="works-table">
        <thead>
            <tr>
                <th style="width: 8%;">№</th>
                <th style="width: 42%;">Наименование работ (услуг), спецификация и характеристика работ</th>
                <th style="width: 10%;">Ед. изм.</th>
                <th style="width: 12%;">Количество</th>
                <th style="width: 14%;">Цена</th>
                <th style="width: 14%;">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @if($works && $works->count() > 0)
                @foreach($works as $index => $work)
                    <tr>
                        <td style="text-align: center;">{{ $index + 1 }}</td>
                        <td>
                            {{ $work->workType->name ?? 'Не указано' }}
                            @if($work->materials && $work->materials->isNotEmpty())
                                <br><small style="color: #666;">
                                    Материалы: @foreach($work->materials as $material){{ $material->name ?? 'Не указан' }} ({{ $material->pivot->quantity ?? 0 }} {{ $material->unit ?? '' }}){{ !$loop->last ? ', ' : '' }}@endforeach
                                </small>
                            @endif
                        </td>
                        <td style="text-align: center;">{{ $work->unit ?? 'шт.' }}</td>
                        <td style="text-align: right;">{{ number_format($work->quantity ?? 0, 2, ',', ' ') }}</td>
                        <td style="text-align: right;">{{ number_format($work->unit_price ?? 0, 2, ',', ' ') }}</td>
                        <td style="text-align: right;">{{ number_format($work->total_amount ?? 0, 2, ',', ' ') }}</td>
                    </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="6" style="text-align: center; padding: 20px; font-style: italic;">
                        Нет выполненных работ
                    </td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align: right; font-weight: bold; padding: 8px;">ИТОГО:</td>
                <td style="text-align: right; font-weight: bold; padding: 8px;">{{ number_format($total_amount, 2, ',', ' ') }}</td>
            </tr>
        </tfoot>
    </table>

    <div style="margin-top: 25px;">
        Всего выполнено работ на сумму: <strong>{{ number_format($total_amount, 2, ',', ' ') }} ({{ $total_amount_words ?? 'Не указано' }}) рублей 00 копеек</strong>
    </div>
    
    <div style="margin-top: 20px;">
        Указанные в настоящем акте работы выполнены полностью с надлежащим качеством.
    </div>

    <div style="margin-top: 40px;">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="width: 50%; border: none; vertical-align: top;">
                    <div><strong>Подрядчик:</strong></div>
                    <div style="margin-top: 15px;">{{ $contractor->name ?? 'ООО «АЛП СТРОЙК»' }}</div>
                    <div style="margin-top: 40px;">
                        _________________ {{ $contractor->director_name ?? 'Нуртдинова Х.Х.' }}
                    </div>
                    <div style="margin-top: 5px; font-size: 10px; text-align: center; color: #666;">
                        (подпись)
                    </div>
                    <div style="margin-top: 15px;">
                        МП
                    </div>
                </td>
                <td style="width: 50%; border: none; vertical-align: top;">
                    <div><strong>Заказчик:</strong></div>
                    <div style="margin-top: 15px;">{{ $contract->organization->name ?? 'Организация' }}</div>
                    <div style="margin-top: 40px;">
                        _________________ {{ $contract->organization->representative_name ?? 'Представитель' }}
                    </div>
                    <div style="margin-top: 5px; font-size: 10px; text-align: center; color: #666;">
                        (подпись)
                    </div>
                    <div style="margin-top: 15px;">
                        МП
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html> 