<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogArticleResource\Schemas;

final class BlogEditorBlockCatalog
{
    /**
     * @return array<string, array{label: string, icon: string, defaultData: array<string, mixed>}>
     */
    public static function definitions(): array
    {
        return [
            'paragraph' => [
                'label' => trans_message('blog_cms.editor_block_paragraph'),
                'icon' => 'bars-3-bottom-left',
                'defaultData' => ['content' => ''],
            ],
            'heading' => [
                'label' => trans_message('blog_cms.editor_block_heading'),
                'icon' => 'hashtag',
                'defaultData' => ['content' => '', 'level' => 2],
            ],
            'list' => [
                'label' => trans_message('blog_cms.editor_block_list'),
                'icon' => 'list-bullet',
                'defaultData' => ['style' => 'unordered', 'items' => [['value' => '']]],
            ],
            'quote' => [
                'label' => trans_message('blog_cms.editor_block_quote'),
                'icon' => 'chat-bubble-left-ellipsis',
                'defaultData' => ['content' => '', 'caption' => ''],
            ],
            'image' => [
                'label' => trans_message('blog_cms.editor_block_image'),
                'icon' => 'photo',
                'defaultData' => ['url' => '', 'alt' => '', 'caption' => ''],
            ],
            'gallery' => [
                'label' => trans_message('blog_cms.editor_block_gallery'),
                'icon' => 'squares-2x2',
                'defaultData' => ['images' => [['url' => '', 'alt' => '']]],
            ],
            'table' => [
                'label' => trans_message('blog_cms.editor_block_table'),
                'icon' => 'table-cells',
                'defaultData' => [
                    'headers' => [['value' => ''], ['value' => '']],
                    'rows' => [
                        ['cells' => [['value' => ''], ['value' => '']]],
                    ],
                ],
            ],
            'code' => [
                'label' => trans_message('blog_cms.editor_block_code'),
                'icon' => 'code-bracket',
                'defaultData' => ['language' => '', 'content' => ''],
            ],
            'divider' => [
                'label' => trans_message('blog_cms.editor_block_divider'),
                'icon' => 'minus',
                'defaultData' => [],
            ],
            'callout' => [
                'label' => trans_message('blog_cms.editor_block_callout'),
                'icon' => 'megaphone',
                'defaultData' => ['variant' => 'info', 'title' => '', 'content' => ''],
            ],
            'embed' => [
                'label' => trans_message('blog_cms.editor_block_embed'),
                'icon' => 'play-circle',
                'defaultData' => ['url' => ''],
            ],
            'cta' => [
                'label' => trans_message('blog_cms.editor_block_cta'),
                'icon' => 'cursor-arrow-rays',
                'defaultData' => ['label' => '', 'url' => '', 'description' => ''],
            ],
        ];
    }

    /**
     * @return array<int, array{type: string, label: string, icon: string, defaultData: array<string, mixed>}>
     */
    public static function forEditor(): array
    {
        return collect(self::definitions())
            ->map(fn (array $definition, string $type): array => ['type' => $type] + $definition)
            ->values()
            ->all();
    }
}
