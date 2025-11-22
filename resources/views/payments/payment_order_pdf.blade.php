<!DOCTYPE html>
<html lang="ru">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Платежное поручение № {{ $document->document_number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            line-height: 1.2;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td, th {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: top;
        }
        .no-border {
            border: none;
        }
        .header-table td {
            border: none;
        }
        .amount-table td {
            text-align: center;
        }
        .field-label {
            font-size: 8px;
            color: #000;
            margin-top: 2px;
        }
        .field-value {
            font-weight: bold;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .mb-10 {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

    <table class="header-table mb-10">
        <tr>
            <td style="width: 150px;"></td>
            <td class="text-right" style="font-size: 9px;">
                0401060
            </td>
        </tr>
    </table>

    <table class="mb-10">
        <tr>
            <td style="width: 120px; border: none;">
                <strong>ПЛАТЕЖНОЕ ПОРУЧЕНИЕ №</strong>
            </td>
            <td style="width: 60px; border: none; border-bottom: 1px solid #000;">
                {{ $document->document_number }}
            </td>
            <td style="width: 10px; border: none;"></td>
            <td style="width: 80px; border: none;">
                <strong>ДАТА</strong>
            </td>
            <td style="width: 100px; border: none; border-bottom: 1px solid #000;">
                {{ $document->document_date->format('d.m.Y') }}
            </td>
            <td style="width: 10px; border: none;"></td>
            <td style="width: 80px; border: none;">
                <strong>ВИД ПЛАТЕЖА</strong>
            </td>
            <td style="border: none; border-bottom: 1px solid #000;">
                Электронно
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td rowspan="2" style="width: 150px;">
                Сумма прописью
            </td>
            <td colspan="5" style="border-bottom: none;">
                {{ \App\Helpers\NumberToWordsHelper::amountToWords($document->amount) }}
            </td>
        </tr>
        <tr>
            <td colspan="5" style="border-top: none;"></td>
        </tr>
        <tr>
            <td colspan="2" style="width: 200px;">
                ИНН {{ $document->payerOrganization->inn ?? $document->payerContractor->inn ?? '' }}
            </td>
            <td style="width: 100px;">
                КПП {{ $document->payerOrganization->kpp ?? $document->payerContractor->kpp ?? '' }}
            </td>
            <td rowspan="2" style="width: 50px; vertical-align: middle;" class="text-center">
                Сумма
            </td>
            <td rowspan="2" style="width: 150px; vertical-align: middle;" class="text-center">
                {{ number_format($document->amount, 2, '-', ' ') }}
            </td>
            <td rowspan="2" style="width: 30px;"></td>
        </tr>
        <tr>
            <td colspan="3">
                <div class="field-value">{{ $document->getPayerName() }}</div>
                <div class="field-label">Плательщик</div>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <div class="field-value">{{ $document->bank_name }}</div>
                <div class="field-label">Банк плательщика</div>
            </td>
            <td class="text-center">БИК</td>
            <td colspan="2">{{ $document->bank_bik }}</td>
        </tr>
        <tr>
            <td colspan="3">
                <div class="field-label">Банк получателя</div>
                <div class="field-value">{{-- Банк получателя --}}</div>
            </td>
            <td class="text-center">Сч. №</td>
            <td colspan="2">{{ $document->bank_correspondent_account }}</td>
        </tr>
        <tr>
            <td colspan="2">
                ИНН {{ $document->payeeOrganization->inn ?? $document->payeeContractor->inn ?? '' }}
            </td>
            <td>
                КПП {{ $document->payeeOrganization->kpp ?? $document->payeeContractor->kpp ?? '' }}
            </td>
            <td class="text-center">Сч. №</td>
            <td colspan="2">{{-- Счет получателя --}}</td>
        </tr>
        <tr>
            <td colspan="3">
                <div class="field-value">{{ $document->getPayeeName() }}</div>
                <div class="field-label">Получатель</div>
            </td>
            <td class="text-center">Вид. оп.</td>
            <td class="text-center">01</td>
            <td class="text-center">Срок плат.</td>
        </tr>
        <tr>
            <td colspan="3" rowspan="4">
                <div class="field-label">Назначение платежа</div>
                <div class="field-value">{{ $document->payment_purpose }}</div>
            </td>
            <td class="text-center">Наз. пл.</td>
            <td></td>
            <td class="text-center">Очер. плат.</td>
        </tr>
        <tr>
            <td class="text-center">Код</td>
            <td></td>
            <td class="text-center">Рез. поле</td>
        </tr>
    </table>

    <div style="margin-top: 20px;">
        <table class="no-border">
            <tr>
                <td class="no-border" style="width: 100px;"><strong>М.П.</strong></td>
                <td class="no-border" style="width: 200px; border-bottom: 1px solid #000;"></td>
                <td class="no-border" style="width: 50px;"></td>
                <td class="no-border" style="width: 200px; border-bottom: 1px solid #000;"></td>
            </tr>
            <tr>
                <td class="no-border"></td>
                <td class="no-border text-center" style="font-size: 8px;">(Подпись)</td>
                <td class="no-border"></td>
                <td class="no-border text-center" style="font-size: 8px;">(Отметки банка)</td>
            </tr>
        </table>
    </div>

</body>
</html>

