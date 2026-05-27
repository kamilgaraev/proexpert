@php
    $document = collect($get('editor_document') ?? []);
    $headings = $document
        ->filter(fn (array $block): bool => ($block['type'] ?? null) === 'heading')
        ->map(function (array $block): array {
            $data = $block['data'] ?? [];

            return [
                'label' => trim((string) ($data['content'] ?? '')),
                'level' => (int) ($data['level'] ?? 2),
            ];
        })
        ->filter(fn (array $heading): bool => $heading['label'] !== '')
        ->values();

@endphp

<div class="space-y-4 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
    <div>
        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
            {{ trans_message('blog_cms.editor_outline_title') }}
        </div>

        @if ($headings->isEmpty())
            <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-300">
                {{ trans_message('blog_cms.editor_outline_empty') }}
            </p>
        @else
            <div class="mt-3 space-y-2">
                @foreach ($headings as $heading)
                    <div class="rounded-xl bg-white px-3 py-2 text-sm text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-100" style="margin-left: {{ max(0, ($heading['level'] - 2) * 12) }}px;">
                        {{ $heading['label'] }}
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
