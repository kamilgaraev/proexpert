@php
    $preview = \App\Filament\Resources\BlogArticleResource\Components\BlogSeoPreview::preview($get, $record);

    $statusClasses = [
        'ok' => 'border-emerald-100 bg-emerald-50 text-emerald-700',
        'warning' => 'border-amber-100 bg-amber-50 text-amber-700',
        'danger' => 'border-red-100 bg-red-50 text-red-700',
    ];
@endphp

<div class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
    <div>
        <div class="text-sm font-semibold text-gray-950">{{ trans_message('blog_cms.seo_preview_title') }}</div>
        <div class="mt-1 text-xs leading-5 text-gray-600">{{ trans_message('blog_cms.seo_preview_description') }}</div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
        <div class="truncate text-sm text-blue-700">{{ $preview['title'] !== '' ? $preview['title'] : trans_message('blog_cms.seo_preview_empty_title') }}</div>
        <div class="mt-1 truncate text-xs text-emerald-700">{{ $preview['url'] }}</div>
        <div class="mt-2 text-xs leading-5 text-gray-600">{{ $preview['description'] !== '' ? $preview['description'] : trans_message('blog_cms.seo_preview_empty_description') }}</div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
        <div class="flex gap-3">
            <div class="h-14 w-20 shrink-0 overflow-hidden rounded-md bg-gray-200">
                @if ($preview['og_image'] !== '')
                    <img src="{{ $preview['og_image'] }}" alt="" class="h-full w-full object-cover">
                @endif
            </div>
            <div class="min-w-0">
                <div class="truncate text-sm font-medium text-gray-950">{{ $preview['og_title'] !== '' ? $preview['og_title'] : trans_message('blog_cms.seo_preview_empty_title') }}</div>
                <div class="mt-1 line-clamp-2 text-xs leading-5 text-gray-600">{{ $preview['og_description'] !== '' ? $preview['og_description'] : trans_message('blog_cms.seo_preview_empty_description') }}</div>
            </div>
        </div>
    </div>

    <div class="space-y-2">
        @foreach ($preview['checks'] as $check)
            <div class="rounded-lg border px-3 py-2 text-xs {{ $statusClasses[$check['status']] ?? $statusClasses['warning'] }}">
                {{ $check['message'] }}
            </div>
        @endforeach
    </div>
</div>
