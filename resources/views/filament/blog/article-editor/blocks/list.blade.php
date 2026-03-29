@php
    $items = collect($items ?? [])->map(fn ($item) => is_array($item) ? ($item['value'] ?? '') : $item)->filter();
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">
        {{ ($style ?? 'unordered') === 'ordered' ? 'Нумерованный список' : 'Маркированный список' }}
    </div>

    @if ($items->isEmpty())
        <p class="mt-3 text-sm text-gray-500">Добавьте пункты списка.</p>
    @else
        <ul class="mt-3 space-y-2 text-sm leading-7 text-gray-700">
            @foreach ($items as $item)
                <li class="flex gap-3">
                    <span class="mt-2 h-1.5 w-1.5 rounded-full bg-gray-900"></span>
                    <span>{{ $item }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
