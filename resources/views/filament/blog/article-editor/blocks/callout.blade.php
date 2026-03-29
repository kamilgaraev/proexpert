@php
    $styles = [
        'info' => 'border-sky-200 bg-sky-50 text-sky-900',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900',
    ];
    $style = $styles[$variant ?? 'info'] ?? $styles['info'];
@endphp

<div class="rounded-2xl border p-4 shadow-sm {{ $style }}">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em]">Callout</div>
    @if (filled($title ?? null))
        <div class="mt-3 text-base font-semibold">{{ $title }}</div>
    @endif
    @if (filled($content ?? null))
        <p class="mt-2 text-sm leading-6">{{ $content }}</p>
    @endif
</div>
