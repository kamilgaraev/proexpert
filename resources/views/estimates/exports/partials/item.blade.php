{{-- Item row --}}
<tr>
    <td class="text-center" style="color: #718096; font-weight: 500;">{{ $item['position_number'] }}</td>
    <td class="text-center" style="font-size: 7.5pt; color: #4a5568;">
        {{ $item['normative_rate_code'] ?? '' }}
        @if($item['is_not_accounted']) <span style="color: #e53e3e; font-weight: bold;">(Н)</span> @endif
    </td>
    <td class="{{ $indent > 0 ? 'item-indent-' . min($indent, 2) : '' }}">
        <strong>{{ $item['name'] }}</strong>
    </td>
    <td class="text-center" style="color: #4a5568;">{{ $item['measurement_unit'] ?? '' }}</td>
    <td class="text-right">{{ number_format($item['quantity_total'], 4, '.', '') }}</td>
    @if($options['show_prices'])
    <td class="text-right" style="color: #2d3748;">{{ number_format($item['unit_price'] ?? 0, 2, '.', ' ') }}</td>
    <td class="text-right" style="font-weight: bold; color: #2d3748;">{{ number_format($item['total_amount'] ?? 0, 2, '.', ' ') }}</td>
    @endif
    <td style="font-size: 7pt; color: #718096; font-style: italic;">
        @if(!empty($item['description']))
            {{ $item['description'] }}
        @endif
        @if($options['include_coefficients'] && !empty($item['applied_coefficients']))
            @if(!empty($item['description'])); @endif
            <strong style="color: #2c5282;">К:</strong>
            @foreach($item['applied_coefficients'] as $key => $coefficient)
                @if(!$loop->first), @endif
                <span style="color: #2c5282;">{{ is_array($coefficient) ? ($coefficient['name'] ?? $key) : $key }}={{ is_array($coefficient) ? ($coefficient['value'] ?? '') : $coefficient }}</span>
            @endforeach
        @endif
    </td>
</tr>

{{-- Child items (materials, machinery, labor) --}}
@if(!empty($item['child_items']))
    @foreach($item['child_items'] as $childItem)
        @include('estimates.exports.partials.item', ['item' => $childItem, 'indent' => $indent + 1, 'options' => $options])
    @endforeach
@endif
