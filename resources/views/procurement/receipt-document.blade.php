@php
    $formatMoney = static fn ($value): string => number_format((float) $value, 2, ',', ' ');
    $formatQuantity = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ',');
    $formatDate = static function ($value): string {
        if (!$value) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    };
    $partyText = static function ($party): string {
        $parts = array_filter([
            data_get($party, 'name'),
            data_get($party, 'inn') ? 'ИНН '.data_get($party, 'inn') : null,
            data_get($party, 'address'),
            data_get($party, 'phone') ? 'тел. '.data_get($party, 'phone') : null,
            data_get($party, 'email'),
        ]);

        return implode(', ', $parts);
    };
    $supplier = data_get($document, 'supplier');
    $shipper = data_get($document, 'shipper') ?: $supplier;
    $consignee = data_get($document, 'consignee');
    $payer = data_get($document, 'payer') ?: $consignee;
    $basis = data_get($document, 'basis', []);
    $rows = collect(data_get($document, 'rows', []));
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ data_get($document, 'title', 'Товарная накладная') }} № {{ data_get($document, 'document_number') }}</title>
    <style>
        @page {
            margin: 7mm;
            size: A4 landscape;
        }

        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 7.5pt;
            line-height: 1.15;
            color: #000;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        td,
        th {
            vertical-align: top;
        }

        .top-codes {
            width: 250px;
            margin-left: auto;
            margin-bottom: 4px;
        }

        .top-codes td {
            border: 1px solid #000;
            padding: 2px 4px;
        }

        .approved {
            text-align: right;
            font-size: 6.7pt;
            margin-bottom: 4px;
        }

        .title {
            text-align: center;
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 0;
            margin: 2px 0 6px;
        }

        .doc-meta {
            width: 290px;
            margin: 0 auto 7px;
        }

        .doc-meta td {
            border: 1px solid #000;
            padding: 3px 5px;
            text-align: center;
        }

        .party-table {
            margin-bottom: 6px;
        }

        .party-table td {
            border: 1px solid #000;
            padding: 3px 4px;
        }

        .party-label {
            width: 118px;
            font-weight: 700;
            background: #f4f4f4;
        }

        .party-code {
            width: 78px;
            text-align: center;
        }

        .items th,
        .items td {
            border: 1px solid #000;
            padding: 2px 3px;
        }

        .items th {
            text-align: center;
            font-weight: 700;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .nowrap {
            white-space: nowrap;
        }

        .signatures {
            margin-top: 8px;
        }

        .signatures td {
            padding: 7px 10px 3px 0;
        }

        .line {
            display: inline-block;
            border-bottom: 1px solid #000;
            width: 135px;
            height: 10px;
            vertical-align: bottom;
        }

        .small-line {
            display: inline-block;
            border-bottom: 1px solid #000;
            width: 90px;
            height: 10px;
            vertical-align: bottom;
        }

        .hint {
            font-size: 6pt;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="approved">
        {{ data_get($document, 'approved_by', 'Унифицированная форма № ТОРГ-12, утверждена постановлением Госкомстата России от 25.12.1998 № 132') }}
    </div>

    <table class="top-codes">
        <tr>
            <td>Форма по ОКУД</td>
            <td class="text-center nowrap">{{ data_get($document, 'okud', '0330212') }}</td>
        </tr>
        <tr>
            <td>по ОКПО</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>Вид деятельности по ОКДП</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td>Вид операции</td>
            <td class="text-center">{{ data_get($document, 'operation_type') }}</td>
        </tr>
    </table>

    <div class="title">{{ mb_strtoupper((string) data_get($document, 'title', 'Товарная накладная')) }}</div>

    <table class="doc-meta">
        <tr>
            <td>Номер документа</td>
            <td>Дата составления</td>
        </tr>
        <tr>
            <td><strong>{{ data_get($document, 'document_number') }}</strong></td>
            <td><strong>{{ $formatDate(data_get($document, 'document_date')) }}</strong></td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td class="party-label">Грузоотправитель</td>
            <td>{{ $partyText($shipper) }}</td>
            <td class="party-code">по ОКПО</td>
            <td class="party-code">&nbsp;</td>
        </tr>
        <tr>
            <td class="party-label">Грузополучатель</td>
            <td>{{ $partyText($consignee) }}</td>
            <td class="party-code">по ОКПО</td>
            <td class="party-code">&nbsp;</td>
        </tr>
        <tr>
            <td class="party-label">Поставщик</td>
            <td>{{ $partyText($supplier) }}</td>
            <td class="party-code">по ОКПО</td>
            <td class="party-code">&nbsp;</td>
        </tr>
        <tr>
            <td class="party-label">Плательщик</td>
            <td>{{ $partyText($payer) }}</td>
            <td class="party-code">по ОКПО</td>
            <td class="party-code">&nbsp;</td>
        </tr>
        <tr>
            <td class="party-label">Основание</td>
            <td>
                {{ data_get($basis, 'document_type') }}
                № {{ data_get($basis, 'number') }}
                @if(data_get($basis, 'date'))
                    от {{ $formatDate(data_get($basis, 'date')) }}
                @endif
            </td>
            <td class="party-code">номер</td>
            <td class="party-code">{{ data_get($basis, 'number') }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th rowspan="2" style="width: 22px;">№</th>
                <th rowspan="2">Товар</th>
                <th rowspan="2" style="width: 55px;">Код</th>
                <th colspan="2">Единица измерения</th>
                <th rowspan="2" style="width: 55px;">Вид упаковки</th>
                <th rowspan="2" style="width: 42px;">Кол-во мест</th>
                <th rowspan="2" style="width: 55px;">Масса брутто</th>
                <th rowspan="2" style="width: 60px;">Количество</th>
                <th rowspan="2" style="width: 65px;">Цена, руб.</th>
                <th rowspan="2" style="width: 72px;">Сумма без НДС, руб.</th>
                <th colspan="2">НДС</th>
                <th rowspan="2" style="width: 78px;">Сумма с НДС, руб.</th>
            </tr>
            <tr>
                <th style="width: 55px;">наименование</th>
                <th style="width: 42px;">код ОКЕИ</th>
                <th style="width: 42px;">ставка</th>
                <th style="width: 65px;">сумма</th>
            </tr>
            <tr class="hint">
                @for($i = 1; $i <= 14; $i++)
                    <th>{{ $i }}</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    <td class="text-center">{{ data_get($row, 'row_number') }}</td>
                    <td>{{ data_get($row, 'name') }}</td>
                    <td class="text-center">{{ data_get($row, 'code') }}</td>
                    <td class="text-center">{{ data_get($row, 'unit_name') }}</td>
                    <td class="text-center">{{ data_get($row, 'okei_code') }}</td>
                    <td class="text-center">{{ data_get($row, 'package_type') }}</td>
                    <td class="text-center">{{ data_get($row, 'places_count') }}</td>
                    <td class="text-right">{{ data_get($row, 'gross_weight') }}</td>
                    <td class="text-right nowrap">{{ $formatQuantity(data_get($row, 'quantity')) }}</td>
                    <td class="text-right nowrap">{{ $formatMoney(data_get($row, 'price')) }}</td>
                    <td class="text-right nowrap">{{ $formatMoney(data_get($row, 'amount_without_vat')) }}</td>
                    <td class="text-center">{{ data_get($row, 'vat_rate') ?? 'без НДС' }}</td>
                    <td class="text-right nowrap">{{ $formatMoney(data_get($row, 'vat_amount')) }}</td>
                    <td class="text-right nowrap">{{ $formatMoney(data_get($row, 'amount_with_vat')) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="8" class="text-right"><strong>Итого</strong></td>
                <td class="text-right nowrap"><strong>{{ $formatQuantity(data_get($document, 'totals.quantity')) }}</strong></td>
                <td>&nbsp;</td>
                <td class="text-right nowrap"><strong>{{ $formatMoney(data_get($document, 'totals.amount_without_vat')) }}</strong></td>
                <td>&nbsp;</td>
                <td class="text-right nowrap"><strong>{{ $formatMoney(data_get($document, 'totals.vat_amount')) }}</strong></td>
                <td class="text-right nowrap"><strong>{{ $formatMoney(data_get($document, 'totals.amount_with_vat')) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                Отпуск груза разрешил
                <span class="small-line"></span>
                <span class="line"></span>
                <span class="line"></span>
                <br><span class="hint">должность&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;подпись&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;расшифровка подписи</span>
            </td>
            <td>
                Главный бухгалтер
                <span class="line"></span>
                <span class="line"></span>
                <br><span class="hint">подпись&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;расшифровка подписи</span>
            </td>
        </tr>
        <tr>
            <td>
                Отпуск груза произвел
                <span class="small-line"></span>
                <span class="line"></span>
                <span class="line"></span>
                <br><span class="hint">должность&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;подпись&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;расшифровка подписи</span>
            </td>
            <td>
                Груз принял
                <span class="small-line"></span>
                <span class="line"></span>
                <span class="line"></span>
                <br><span class="hint">должность&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;подпись&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;расшифровка подписи</span>
            </td>
        </tr>
        <tr>
            <td>
                М.П. «____» __________________ {{ now()->format('Y') }} г.
            </td>
            <td>
                Груз получил грузополучатель
                <span class="small-line"></span>
                <span class="line"></span>
                <span class="line"></span>
                <br><span class="hint">должность&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;подпись&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;расшифровка подписи</span>
            </td>
        </tr>
    </table>
</body>
</html>
