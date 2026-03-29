<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
    @if (filled($url ?? null))
        <img src="{{ $url }}" alt="{{ $alt ?? '' }}" class="h-48 w-full object-cover">
    @else
        <div class="flex h-48 items-center justify-center bg-gray-100 text-sm text-gray-500">Изображение не выбрано</div>
    @endif

    <div class="p-4">
        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Изображение</div>
        @if (filled($caption ?? null))
            <p class="mt-3 text-sm leading-6 text-gray-700">{{ $caption }}</p>
        @elseif (filled($alt ?? null))
            <p class="mt-3 text-sm leading-6 text-gray-600">{{ $alt }}</p>
        @endif
    </div>
</div>
