<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Остатки склада</title>
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #263238; }
        h1 { font-size: 17px; margin: 0 0 8px; }
        .meta { margin-bottom: 14px; color: #546e7a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cfd8dc; padding: 5px; vertical-align: top; }
        th { background: #eaf2ff; text-align: left; }
        .number { text-align: right; white-space: nowrap; }
        .low { color: #a84300; font-weight: bold; }
        .footer { margin-top: 12px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Остатки склада: {{ $warehouse->name }}</h1>
    <div class="meta">Сформировано: {{ $generatedAt }} · Позиций: {{ count($rows) }}</div>
    <table>
        <thead>
            <tr>
                <th>Позиция</th>
                <th>Код</th>
                <th>Ед. изм.</th>
                <th class="number">Доступно</th>
                <th class="number">Резерв</th>
                <th class="number">Всего</th>
                <th class="number">Средняя цена, ₽</th>
                <th class="number">Стоимость, ₽</th>
                <th>Адрес</th>
                <th>Состояние</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $available = (float) $row->available_quantity;
                    $reserved = (float) $row->reserved_quantity;
                    $total = $available + $reserved;
                    $value = (float) $row->total_value;
                    $low = (float) $row->min_stock_level > 0 && $available <= (float) $row->min_stock_level;
                @endphp
                <tr>
                    <td>{{ $row->material_name }}</td>
                    <td>{{ $row->material_code }}</td>
                    <td>{{ $row->unit_short_name ?: $row->unit_name }}</td>
                    <td class="number">{{ number_format($available, 2, ',', ' ') }}</td>
                    <td class="number">{{ number_format($reserved, 2, ',', ' ') }}</td>
                    <td class="number">{{ number_format($total, 2, ',', ' ') }}</td>
                    <td class="number">{{ number_format($total > 0 ? $value / $total : 0, 2, ',', ' ') }}</td>
                    <td class="number">{{ number_format($value, 2, ',', ' ') }}</td>
                    <td>{{ $row->storage_address }}</td>
                    <td class="{{ $low ? 'low' : '' }}">{{ $low ? 'Низкий остаток' : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="10">По заданным фильтрам остатков нет.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="footer">Общая стоимость: {{ number_format($totalValue, 2, ',', ' ') }} ₽</div>
</body>
</html>
