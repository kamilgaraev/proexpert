@php
    $source = $record ?? [];

    if (isset($get) && is_callable($get)) {
        $source = [
            'title' => $get('title'),
            'slug' => $get('slug'),
            'excerpt' => $get('excerpt'),
            'canonical_url' => $get('canonical_url'),
            'content' => $record?->content,
            'editor_document' => $get('editor_document') ?? [],
            'featured_image' => $get('featured_image'),
            'category_id' => $get('category_id'),
            'author_id' => $record?->author_id,
            'author_system_admin_id' => $get('author_system_admin_id') ?? $record?->author_system_admin_id,
            'meta_title' => $get('meta_title'),
            'meta_description' => $get('meta_description'),
            'status' => $get('status'),
            'scheduled_at' => $get('scheduled_at'),
        ];
    }

    $result = app(\App\Services\Blog\BlogEditorialChecklistService::class)->evaluate($source, $record?->id);
    $percent = $result['total'] > 0 ? (int) round(($result['passed'] / $result['total']) * 100) : 0;
@endphp

<div class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="text-sm font-semibold text-gray-950">{{ trans_message('blog_cms.editorial_checklist_section') }}</div>
            <div class="mt-1 text-sm text-gray-600">{{ $result['passed'] }}/{{ $result['total'] }}</div>
        </div>
        <div class="rounded-lg px-3 py-1 text-sm font-semibold {{ $result['can_publish'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
            {{ $result['can_publish'] ? trans_message('blog_cms.checklist_ready') : trans_message('blog_cms.checklist_needs_work') }}
        </div>
    </div>

    <div class="h-2 overflow-hidden rounded-full bg-gray-100">
        <div class="h-full rounded-full bg-gray-900" style="width: {{ $percent }}%;"></div>
    </div>

    <div class="grid gap-2 md:grid-cols-2">
        @foreach ($result['items'] as $item)
            <div class="rounded-lg border {{ $item['passed'] ? 'border-emerald-100 bg-emerald-50/60' : 'border-amber-100 bg-amber-50/70' }} px-3 py-2">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium text-gray-900">{{ $item['label'] }}</div>
                        <div class="mt-1 text-xs leading-5 text-gray-600">{{ $item['message'] }}</div>
                    </div>
                    <div class="shrink-0 text-xs font-semibold {{ $item['passed'] ? 'text-emerald-700' : 'text-amber-700' }}">
                        {{ $item['passed'] ? trans_message('blog_cms.checklist_ready') : trans_message('blog_cms.checklist_needs_work') }}
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
