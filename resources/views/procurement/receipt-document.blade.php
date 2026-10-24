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
    $rowsCountWords = \App\Helpers\NumberToWordsHelper::integerToWords($rows->count());
    $amount = data_get($document, 'totals.amount_with_vat');
    $amountSource = match (true) {
        is_string($amount), is_int($amount) => (string) $amount,
        is_float($amount) && is_finite($amount) => json_encode($amount, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
        default => '',
    };
    $amountRublesWords = '';
    $amountKopecks = '';
    if (preg_match('/^([0-9]{1,13})(?:\.([0-9]{1,2}))?$/D', $amountSource, $amountParts)) {
        $amountRublesWords = \App\Helpers\NumberToWordsHelper::integerToWords((int) $amountParts[1]);
        $amountKopecks = str_pad($amountParts[2] ?? '', 2, '0');
    }
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
            font-size: 6.5pt;
        }

        .top-codes td {
            border: 1px solid #000;
            padding: 1px 4px;
        }
        .form-header { margin-bottom: 5px; }
        .form-header .document-heading { vertical-align: middle; }
        .form-header .document-codes { width: 250px; }

        .approved {
            text-align: right;
            font-size: 6.7pt;
            margin-bottom: 4px;
        }

        .title {
            text-align: center;
            font-size: 13pt;
            font-weight: 700;
            letter-spacing: 0;
            margin: 2px 0 4px;
        }

        .doc-meta {
            width: 290px;
            margin: 0 auto 7px;
        }

        .doc-meta td {
            border: 1px solid #000;
            padding: 2px 5px;
            text-align: center;
        }

        .party-table {
            margin-bottom: 6px;
        }

        .party-table td {
            border: 1px solid #000;
            padding: 0 4px;
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
            padding: 2px;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .items { table-layout: fixed; font-size: 6.5pt; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items .document-reference { text-align: left; font-size: 6pt; }
        .items .column-widths td { border: 0; padding: 0; height: 0; line-height: 0; font-size: 0; }
        .items .nowrap strong { font-size: 6pt; }

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

        .form-summary { margin-top: 3px; }
        .form-summary td { padding: 1px 3px; }
        .closing { margin-top: 4px; page-break-inside: avoid; table-layout: fixed; }
        .closing-side { width: 50%; padding: 2px 8px 0 0; }
        .closing-side + .closing-side { padding-left: 8px; padding-right: 0; }
        .closing p { margin: 3px 0; }
        .field {
            display: inline-block;
            border-bottom: 1px solid #000;
            min-width: 60px;
            height: 10px;
            vertical-align: bottom;
        }
        .words-field { border-bottom: 1px solid #000; min-height: 11px; padding: 1px 3px; }
        .signature-block { margin-top: 5px; }
        .signature-block td { padding: 1px 4px 0 0; }
        .signature-block .sign-line { border-bottom: 1px solid #000; height: 10px; }

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

    <table class="form-header">
        <tr>
            <td class="document-heading">
                <div class="title">{{ mb_strtoupper((string) data_get($document, 'title', 'Товарная накладная')) }}</div>
                <table class="doc-meta">
                    <tr><td>Номер документа</td><td>Дата составления</td></tr>
                    <tr>
                        <td><strong>{{ data_get($document, 'document_number') }}</strong></td>
                        <td><strong>{{ $formatDate(data_get($document, 'document_date')) }}</strong></td>
                    </tr>
                </table>
            </td>
            <td class="document-codes">
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
            </td>
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
        <tr>
            <td class="party-label">Дата основания</td>
            <td>{{ $formatDate(data_get($basis, 'date')) }}</td>
            <td class="party-code">дата</td>
            <td class="party-code">{{ $formatDate(data_get($basis, 'date')) }}</td>
        </tr>
        <tr>
            <td rowspan="2" class="party-label">Транспортная накладная</td>
            <td rowspan="2">&nbsp;</td>
            <td class="party-code">номер</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td class="party-code">дата</td>
            <td>&nbsp;</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr class="column-widths" aria-hidden="true">
                @foreach([2, 17, 4, 4, 3, 4, 4, 4, 4, 10, 10, 10, 4, 10, 10] as $width)
                    <td style="width: {{ $width }}%;"></td>
                @endforeach
            </tr>
            <tr>
                <th colspan="15" class="document-reference">ТОРГ-12 № {{ data_get($document, 'document_number') }} от {{ $formatDate(data_get($document, 'document_date')) }}</th>
            </tr>
            <tr>
                <th rowspan="2">№ п/п</th>
                <th colspan="2">Товар</th>
                <th colspan="2">Единица измерения</th>
                <th rowspan="2">Вид упаковки</th>
                <th colspan="2">Количество</th>
                <th rowspan="2">Масса брутто</th>
                <th rowspan="2">Количество (масса нетто)</th>
                <th rowspan="2">Цена, руб. коп.</th>
                <th rowspan="2">Сумма без учета НДС, руб. коп.</th>
                <th colspan="2">НДС</th>
                <th rowspan="2">Сумма с учетом НДС, руб. коп.</th>
            </tr>
            <tr>
                <th>наименование, характеристика, сорт, артикул</th>
                <th>код</th>
                <th>наименование</th>
                <th>код по ОКЕИ</th>
                <th>в одном месте</th>
                <th>мест, штук</th>
                <th>ставка, %</th>
                <th>сумма, руб. коп.</th>
            </tr>
            <tr class="hint">
                @for($i = 1; $i <= 15; $i++)
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
                    <td class="text-center">{{ data_get($row, 'quantity_in_package') }}</td>
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
                <td colspan="9" class="text-right"><strong>Всего по накладной</strong></td>
                <td class="text-right nowrap"><strong>{{ $formatQuantity(data_get($document, 'totals.quantity')) }}</strong></td>
                <td>&nbsp;</td>
                <td class="text-right nowrap"><strong>{{ $formatMoney(data_get($document, 'totals.amount_without_vat')) }}</strong></td>
                <td>&nbsp;</td>
                <td class="text-right nowrap"><strong>{{ $formatMoney(data_get($document, 'totals.vat_amount')) }}</strong></td>
                <td class="text-right nowrap"><strong>{{ $formatMoney(data_get($document, 'totals.amount_with_vat')) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <table class="form-summary">
        <tr>
            <td colspan="2">Товарная накладная имеет приложение на <span class="field"></span> листах</td>
        </tr>
        <tr>
            <td colspan="2">и содержит <strong>{{ $rowsCountWords }}</strong> порядковых номеров записей <span class="hint">(прописью)</span></td>
        </tr>
        <tr>
            <td>Масса груза (нетто) <span class="field" style="min-width: 180px;"></span> <span class="hint">(прописью)</span></td>
            <td>Масса груза (брутто) <span class="field" style="min-width: 180px;"></span> <span class="hint">(прописью)</span></td>
        </tr>
        <tr><td colspan="2">Всего мест <span class="field" style="min-width: 180px;"></span> <span class="hint">(прописью)</span></td></tr>
    </table>

    <table class="closing">
        <tr>
            <td class="closing-side">
                <p>Приложение (паспорта, сертификаты и т.п.) на <span class="field"></span> листах</p>
                <p>Всего отпущено на сумму</p>
                <div class="words-field">{{ $amountRublesWords }} руб. {{ $amountKopecks }} коп.</div>
                <div class="hint">прописью</div>
                <table class="signature-block">
                    <tr><td colspan="3">Отпуск груза разрешил</td></tr>
                    <tr><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td></tr>
                    <tr class="hint"><td>должность</td><td>подпись</td><td>расшифровка подписи</td></tr>
                    <tr><td colspan="3">Главный (старший) бухгалтер</td></tr>
                    <tr><td>&nbsp;</td><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td></tr>
                    <tr class="hint"><td></td><td>подпись</td><td>расшифровка подписи</td></tr>
                    <tr><td colspan="3">Отпуск груза произвел</td></tr>
                    <tr><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td></tr>
                    <tr class="hint"><td>должность</td><td>подпись</td><td>расшифровка подписи</td></tr>
                </table>
                <p>М.П. «____» __________________ ______ г.</p>
            </td>
            <td class="closing-side">
                <p>По доверенности № <span class="field"></span> от «____» ______________ ______ года,</p>
                <p>выданной <span class="field" style="min-width: 260px;"></span></p>
                <div class="hint">кем, кому (организация, должность, фамилия, инициалы)</div>
                <div class="words-field">&nbsp;</div>
                <table class="signature-block">
                    <tr><td colspan="3">Груз принял</td></tr>
                    <tr><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td></tr>
                    <tr class="hint"><td>должность</td><td>подпись</td><td>расшифровка подписи</td></tr>
                    <tr><td colspan="3">Груз получил грузополучатель</td></tr>
                    <tr><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td><td class="sign-line">&nbsp;</td></tr>
                    <tr class="hint"><td>должность</td><td>подпись</td><td>расшифровка подписи</td></tr>
                </table>
                <p>М.П. «____» __________________ ______ г.</p>
            </td>
        </tr>
    </table>
</body>
</html>
