@php
    $headerValues = collect($headers ?? [])->map(fn ($cell) => is_array($cell) ? ($cell['value'] ?? '') : $cell)->filter();
    $rowValues = collect($rows ?? [])->map(function ($row) {
        $cells = is_array($row) ? ($row['cells'] ?? $row) : [];

        return collect($cells)->map(fn ($cell) => is_array($cell) ? ($cell['value'] ?? '') : $cell)->filter()->values();
    })->filter(fn ($row) => $row->isNotEmpty());
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Таблица</div>

    @if ($headerValues->isEmpty() && $rowValues->isEmpty())
        <p class="mt-3 text-sm text-gray-500">Добавьте колонки и строки таблицы.</p>
    @else
        <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                @if ($headerValues->isNotEmpty())
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach ($headerValues as $header)
                                <th class="px-3 py-2 text-left font-semibold text-gray-700">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                @endif
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($rowValues as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td class="px-3 py-2 text-gray-700">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
