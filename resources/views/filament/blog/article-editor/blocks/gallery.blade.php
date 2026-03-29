@php
    $items = collect($images ?? [])->map(fn ($image) => is_array($image) ? $image : [])->filter(fn (array $image) => filled($image['url'] ?? null));
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Галерея</div>

    @if ($items->isEmpty())
        <p class="mt-3 text-sm text-gray-500">Добавьте изображения в галерею.</p>
    @else
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            @foreach ($items as $image)
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
                    <img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}" class="h-32 w-full object-cover">
                </div>
            @endforeach
        </div>
    @endif
</div>
