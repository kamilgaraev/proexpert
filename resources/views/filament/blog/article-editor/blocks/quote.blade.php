<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Цитата</div>
    <blockquote class="mt-3 border-l-4 border-gray-900 pl-4 text-sm leading-7 text-gray-700">
        {{ $content ?? 'Текст цитаты' }}
    </blockquote>
    @if (filled($caption ?? null))
        <div class="mt-3 text-xs font-semibold uppercase tracking-[0.14em] text-gray-500">{{ $caption }}</div>
    @endif
</div>
