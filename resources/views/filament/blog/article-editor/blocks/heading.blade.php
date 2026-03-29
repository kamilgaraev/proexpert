@php $tag = 'H' . ((int) ($level ?? 2)); @endphp

<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">{{ $tag }}</div>
    <div class="mt-3 text-lg font-semibold text-gray-950">
        {{ $content ?? 'Заголовок блока' }}
    </div>
</div>
