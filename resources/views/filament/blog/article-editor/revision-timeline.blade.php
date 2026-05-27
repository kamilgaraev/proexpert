@php
    $article = $record ?? null;
    $revisionService = app(\App\Services\Blog\BlogRevisionService::class);
    $revisions = $article?->revisions()
        ->with('createdBySystemAdmin')
        ->latest()
        ->limit(12)
        ->get() ?? collect();
@endphp

<div class="space-y-3">
    @if ($revisions->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            {{ trans_message('blog_cms.revision_timeline_empty') }}
        </div>
    @else
        @foreach ($revisions as $revision)
            @php
                $categoryName = data_get($revision->category_snapshot, 'name') ?: trans_message('blog_cms.revision_empty_category');
                $authorName = data_get($revision->author_snapshot, 'name') ?: trans_message('blog_cms.revision_empty_author');
                $changedBy = $revision->createdBySystemAdmin?->name ?: trans_message('blog_cms.revision_system_actor');
                $changedFields = $revisionService->changedFieldSummary($revision, $article);
            @endphp

            <div class="rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">
                            {{ $revision->revision_type?->label() ?? trans_message('blog_cms.revision_type_unknown') }}
                        </span>
                        <span class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ $revision->title }}
                        </span>
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $revision->created_at?->format('d.m.Y H:i') }}
                    </span>
                </div>

                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('blog_cms.revision_status_label') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $revision->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('blog_cms.revision_category_label') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $categoryName }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('blog_cms.revision_author_label') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $authorName }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('blog_cms.revision_changed_by_label') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $changedBy }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('blog_cms.revision_url_label') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $revision->slug }}</dd>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('blog_cms.revision_changed_fields_label') }}</dt>
                        <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $changedFields }}</dd>
                    </div>
                </dl>
            </div>
        @endforeach
    @endif
</div>
