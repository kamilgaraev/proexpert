<article>
    <h1>Спецификация к договору № {{ $specification['contract']['number'] }}</h1>
    @if($specification['contract']['date'])
        <p>Дата договора: {{ $specification['contract']['date'] }}</p>
    @endif
    <p>Валюта: {{ $specification['contract']['currency'] }}. Состав и стоимость приведены по текущим привязкам работ.</p>
    @if($specification['contract']['amount'] !== null)
        <p>Сумма договора: {{ number_format((float) $specification['contract']['amount'], 2, ',', ' ') }}. Условия НДС указаны в строках.</p>
    @endif
    <table>
        <tr><td><p>№</p></td><td><p>Работа / смета</p></td><td><p>Количество</p></td><td><p>Расчётная цена без НДС</p></td><td><p>Стоимость и НДС</p></td></tr>
        @foreach($specification['rows'] as $index => $row)
            <tr>
                <td><p>{{ $index + 1 }}</p></td>
                <td><p>{{ $row['position_number'] }} {{ $row['name'] }}</p><p>Смета № {{ $row['estimate_number'] }}</p></td>
                <td><p>{{ rtrim(rtrim(number_format((float) $row['quantity'], 8, ',', ' '), '0'), ',') }} {{ $row['unit'] }}</p></td>
                <td><p>{{ $row['unit_price_without_vat'] === null ? trans_message('estimate_finance.specification_price_undetermined') : number_format((float) $row['unit_price_without_vat'], 4, ',', ' ') }}</p></td>
                <td>
                    <p>Без НДС: {{ $row['amount_without_vat'] === null ? trans_message('estimate_finance.specification_unpriced') : number_format((float) $row['amount_without_vat'], 2, ',', ' ') }}</p>
                    <p>НДС: {{ $row['vat_mode'] === 'none' ? trans_message('estimate_finance.specification_vat_none') : ($row['vat_rate'] === null ? trans_message('estimate_finance.specification_vat_unspecified') : rtrim(rtrim(number_format((float) $row['vat_rate'], 4, ',', ' '), '0'), ',').' %') }}</p>
                    <p>Всего: {{ $row['amount_with_vat'] === null ? trans_message('estimate_finance.specification_undetermined') : number_format((float) $row['amount_with_vat'], 2, ',', ' ') }} {{ $row['currency'] }}</p>
                    @if(!$row['composition_confirmed'])<p>Состав цены требует подтверждения.</p>@endif
                </td>
            </tr>
        @endforeach
    </table>
    <h2>Итого по спецификации</h2>
    <p>Без НДС: {{ $specification['totals']['amount_without_vat'] === null ? trans_message('estimate_finance.specification_undetermined') : number_format((float) $specification['totals']['amount_without_vat'], 2, ',', ' ') }} {{ $specification['contract']['currency'] }}</p>
    <p>НДС: {{ $specification['totals']['vat_amount'] === null ? trans_message('estimate_finance.specification_vat_undetermined') : number_format((float) $specification['totals']['vat_amount'], 2, ',', ' ') }} {{ $specification['contract']['currency'] }}</p>
    <p>Всего: {{ $specification['totals']['amount_with_vat'] === null ? trans_message('estimate_finance.specification_undetermined') : number_format((float) $specification['totals']['amount_with_vat'], 2, ',', ' ') }} {{ $specification['contract']['currency'] }}</p>
</article>
