<article>
    <h1>Спецификация к договору № {{ $specification['contract']['number'] }}</h1>
    @if($specification['contract']['date'])
        <p>Дата договора: {{ $specification['contract']['date'] }}</p>
    @endif
    <p>Валюта: {{ $specification['contract']['currency'] }}. Состав и стоимость приведены по текущим привязкам работ.</p>
    @if($specification['contract']['amount'] !== null)
        <p>Сумма договора: <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($specification['contract']['amount']) }}</span>. Условия НДС указаны в строках.</p>
    @endif
    <table class="contract-work-specification">
        <colgroup>
            <col style="width:5%">
            <col style="width:31%">
            <col style="width:15%">
            <col style="width:22%">
            <col style="width:27%">
        </colgroup>
        <tr>
            <td style="width:5%"><p>№</p></td>
            <td style="width:31%"><p>Работа / смета</p></td>
            <td style="width:15%"><p>Количество</p></td>
            <td style="width:22%"><p>Расчётная цена без НДС</p></td>
            <td style="width:27%"><p>Стоимость и НДС</p></td>
        </tr>
        @foreach($specification['rows'] as $index => $row)
            <tr>
                <td><p>{{ $index + 1 }}</p></td>
                <td><p>{{ $row['position_number'] }} {{ $row['name'] }}</p><p>Смета № {{ $row['estimate_number'] }}</p></td>
                <td><p>{{ rtrim(rtrim(number_format((float) $row['quantity'], 8, ',', ' '), '0'), ',') }} {{ $row['unit'] }}</p></td>
                <td><p>
                    @if($row['unit_price_without_vat'] === null)
                        {{ trans_message('estimate_finance.specification_price_undetermined') }}
                    @else
                        <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($row['unit_price_without_vat'], 4) }}</span>
                    @endif
                </p></td>
                <td>
                    <p>Без НДС:
                        @if($row['amount_without_vat'] === null)
                            {{ trans_message('estimate_finance.specification_unpriced') }}
                        @else
                            <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($row['amount_without_vat']) }}</span>
                        @endif
                    </p>
                    <p>НДС: {{ $row['vat_mode'] === 'none' ? trans_message('estimate_finance.specification_vat_none') : ($row['vat_rate'] === null ? trans_message('estimate_finance.specification_vat_unspecified') : rtrim(rtrim(number_format((float) $row['vat_rate'], 4, ',', ' '), '0'), ',').' %') }}</p>
                    <p>Всего:
                        @if($row['amount_with_vat'] === null)
                            {{ trans_message('estimate_finance.specification_undetermined') }}
                        @else
                            <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($row['amount_with_vat']) }}</span>
                        @endif
                        {{ $row['currency'] }}
                    </p>
                    @if(!$row['composition_confirmed'])<p>Состав цены требует подтверждения.</p>@endif
                </td>
            </tr>
        @endforeach
    </table>
    <h2>Итого по спецификации</h2>
    <p>Без НДС:
        @if($specification['totals']['amount_without_vat'] === null)
            {{ trans_message('estimate_finance.specification_undetermined') }}
        @else
            <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($specification['totals']['amount_without_vat']) }}</span>
        @endif
        {{ $specification['contract']['currency'] }}
    </p>
    <p>НДС:
        @if($specification['totals']['vat_amount'] === null)
            {{ trans_message('estimate_finance.specification_vat_undetermined') }}
        @else
            <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($specification['totals']['vat_amount']) }}</span>
        @endif
        {{ $specification['contract']['currency'] }}
    </p>
    <p>Всего:
        @if($specification['totals']['amount_with_vat'] === null)
            {{ trans_message('estimate_finance.specification_undetermined') }}
        @else
            <span class="contract-specification-money">{{ \App\Services\Contract\ContractWorkSpecificationMoney::format($specification['totals']['amount_with_vat']) }}</span>
        @endif
        {{ $specification['contract']['currency'] }}
    </p>
</article>
