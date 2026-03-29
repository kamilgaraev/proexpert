<div class="overflow-hidden rounded-2xl border border-gray-200 bg-gray-950 shadow-sm">
    <div class="border-b border-white/10 px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-300">
        {{ filled($language ?? null) ? $language : 'Code block' }}
    </div>
    <pre class="overflow-x-auto px-4 py-4 text-sm leading-6 text-gray-100">{{ $content ?? '' }}</pre>
</div>
