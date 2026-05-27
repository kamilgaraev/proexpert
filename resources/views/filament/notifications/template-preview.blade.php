<div class="space-y-4">
    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('notifications.template_preview_channel') }}</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $preview['channel'] }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('notifications.template_preview_type') }}</dt>
            <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $preview['type'] }}</dd>
        </div>
    </dl>

    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('notifications.template_preview_subject') }}</div>
        <div class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            {{ $preview['subject'] }}
        </div>
    </div>

    <div>
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ trans_message('notifications.template_preview_content') }}</div>
        <div class="mt-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm leading-6 text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            {!! nl2br(e($preview['content'])) !!}
        </div>
    </div>
</div>
