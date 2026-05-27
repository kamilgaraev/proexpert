@php
    $previewUrl = $record ? app(\App\Services\Blog\BlogCmsService::class)->makePreviewUrl($record) : null;
    $version = (int) ($get('editor_version') ?? $record?->editor_version ?? 0);
    $lastAutosavedAt = $record?->last_autosaved_at;
@endphp

<div
    x-data
    x-on:keydown.window.meta.s.prevent="$wire.autosave()"
    x-on:keydown.window.ctrl.s.prevent="$wire.autosave()"
    class="rounded-2xl border border-gray-200 bg-gradient-to-br from-white via-white to-gray-50 p-5 shadow-sm"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="space-y-3">
            <div class="inline-flex items-center gap-2 rounded-full bg-gray-900 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-white">
                {{ trans_message('blog_cms.editor_workspace_badge') }}
            </div>
            <div>
                <h3 class="text-lg font-semibold text-gray-950">{{ trans_message('blog_cms.editor_workspace_title') }}</h3>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600">
                    {{ trans_message('blog_cms.editor_workspace_description') }}
                </p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3 lg:min-w-[360px]">
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Версия</div>
                <div class="mt-2 text-lg font-semibold text-gray-950">v{{ max(1, $version) }}</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">{{ trans_message('blog_cms.editor_reading_time') }}</div>
                <div class="mt-2 text-lg font-semibold text-gray-950">{{ (int) ($get('reading_time') ?? $record?->reading_time ?? 1) }} мин</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white px-4 py-3">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">{{ trans_message('blog_cms.editor_autosave_state') }}</div>
                <div class="mt-2 text-sm font-semibold text-gray-950">
                    {{ $lastAutosavedAt?->diffForHumans() ?? 'Пока нет' }}
                </div>
            </div>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
        <button
            type="button"
            wire:click="autosave"
            class="inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-800"
        >
            {{ trans_message('blog_cms.editor_autosave_action') }}
        </button>

        @if (filled($previewUrl))
            <a
                href="{{ $previewUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-gray-400 hover:text-gray-950"
            >
                {{ trans_message('blog_cms.editor_open_preview') }}
            </a>
        @endif
    </div>
</div>
