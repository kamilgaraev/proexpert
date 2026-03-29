<div class="rounded-2xl border border-gray-200 bg-gradient-to-br from-gray-950 via-gray-900 to-gray-800 p-5 text-white shadow-sm">
    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/60">CTA</div>
    <div class="mt-3 text-lg font-semibold">{{ $label ?? 'Кнопка действия' }}</div>
    @if (filled($description ?? null))
        <p class="mt-2 text-sm leading-6 text-white/75">{{ $description }}</p>
    @endif
    <div class="mt-4 inline-flex rounded-xl bg-white px-4 py-2 text-sm font-semibold text-gray-900">
        {{ $label ?? 'Перейти' }}
    </div>
</div>
