{{-- Item row --}}
<tr>
    <td class="text-center">{{ $item['position_number'] }}</td>
    <td class="text-center">
        {{ $item['normative_rate_code'] ?? '' }}
        @if($item['is_not_accounted']) (Н) @endif
    </td>
    <td class="{{ $indent > 0 ? 'item-indent-' . min($indent, 2) : '' }}">
        {{ $item['name'] }}
    </td>
    <td class="text-center">{{ $item['measurement_unit'] ?? '' }}</td>
    <td class="text-right">{{ number_format($item['quantity_total'], 4, '.', '') }}</td>
    @if($options['show_prices'])
    <td class="text-right">{{ number_format($item['unit_price'] ?? 0, 2, '.', ' ') }}</td>
    <td class="text-right">{{ number_format($item['total_amount'] ?? 0, 2, '.', ' ') }}</td>
    @endif
    <td style="font-size: 7pt;">
        @php
            $notes = [];
            if (!empty($item['description'])) {
                $notes[] = $item['description'];
            }
            if ($options['include_coefficients'] && !empty($item['applied_coefficients'])) {
                $coeffStr = 'К: ' . implode(', ', array_map(
                    fn($k, $v) => "{$k}={$v}",
                    array_keys($item['applied_coefficients']),
                    $item['applied_coefficients']
                ));
                $notes[] = $coeffStr;
            }
        @endphp
        {{ implode('; ', $notes) }}
    </td>
</tr>

{{-- Child items (materials, machinery, labor) --}}
@if(!empty($item['child_items']))
    @foreach($item['child_items'] as $childItem)
        @include('estimates.exports.partials.item', ['item' => $childItem, 'indent' => $indent + 1, 'options' => $options])
    @endforeach
@endif
