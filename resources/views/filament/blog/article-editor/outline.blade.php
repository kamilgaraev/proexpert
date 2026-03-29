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

    $title = trim((string) ($get('title') ?? ''));
    $slug = trim((string) ($get('slug') ?? ''));
    $categoryId = $get('category_id');
    $featuredImage = trim((string) ($get('featured_image') ?? ''));
    $metaTitle = trim((string) ($get('meta_title') ?? ''));
    $metaDescription = trim((string) ($get('meta_description') ?? ''));

    $checklist = [
        ['label' => 'Есть заголовок', 'passed' => $title !== ''],
        ['label' => 'Заполнен slug', 'passed' => $slug !== ''],
        ['label' => 'Выбрана категория', 'passed' => filled($categoryId)],
        ['label' => 'Есть обложка', 'passed' => $featuredImage !== ''],
        ['label' => 'Заполнен meta title', 'passed' => $metaTitle !== ''],
        ['label' => 'Заполнен meta description', 'passed' => $metaDescription !== ''],
    ];

    $completedChecklistItems = collect($checklist)->where('passed', true)->count();
@endphp

<div class="space-y-4 rounded-2xl border border-gray-200 bg-gray-50 p-4">
    <div>
        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Publish health</div>
        <div class="mt-2 text-lg font-semibold text-gray-950">{{ $completedChecklistItems }}/{{ count($checklist) }}</div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-200">
            <div
                class="h-full rounded-full bg-gray-900"
                style="width: {{ count($checklist) > 0 ? ($completedChecklistItems / count($checklist)) * 100 : 0 }}%;"
            ></div>
        </div>
    </div>

    <div class="space-y-2">
        @foreach ($checklist as $item)
            <div class="flex items-center justify-between rounded-xl bg-white px-3 py-2 text-sm">
                <span class="text-gray-700">{{ $item['label'] }}</span>
                <span class="{{ $item['passed'] ? 'text-emerald-700' : 'text-rose-600' }}">
                    {{ $item['passed'] ? 'OK' : 'Нужно заполнить' }}
                </span>
            </div>
        @endforeach
    </div>

    <div class="border-t border-gray-200 pt-4">
        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">Outline</div>

        @if ($headings->isEmpty())
            <p class="mt-3 text-sm leading-6 text-gray-500">
                Добавьте хотя бы один блок заголовка, чтобы увидеть структуру статьи.
            </p>
        @else
            <div class="mt-3 space-y-2">
                @foreach ($headings as $heading)
                    <div class="rounded-xl bg-white px-3 py-2 text-sm text-gray-700" style="margin-left: {{ max(0, ($heading['level'] - 2) * 12) }}px;">
                        {{ $heading['label'] }}
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
