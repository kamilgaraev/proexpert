<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Resources\BlogArticleResource\Schemas\BlogEditorBlockCatalog;
use Tests\TestCase;

class BlogInlineBlockEditorCatalogTest extends TestCase
{
    public function test_catalog_exposes_all_article_editor_block_types_in_order(): void
    {
        self::assertSame([
            'paragraph',
            'heading',
            'list',
            'quote',
            'image',
            'gallery',
            'table',
            'code',
            'divider',
            'callout',
            'embed',
            'cta',
        ], array_keys(BlogEditorBlockCatalog::definitions()));
    }

    public function test_catalog_defaults_match_document_renderer_contract(): void
    {
        $definitions = BlogEditorBlockCatalog::definitions();

        self::assertSame(['content' => ''], $definitions['paragraph']['defaultData']);
        self::assertSame(['content' => '', 'level' => 2], $definitions['heading']['defaultData']);
        self::assertSame(['style' => 'unordered', 'items' => [['value' => '']]], $definitions['list']['defaultData']);
        self::assertSame(['content' => '', 'caption' => ''], $definitions['quote']['defaultData']);
        self::assertSame(['url' => '', 'alt' => '', 'caption' => ''], $definitions['image']['defaultData']);
        self::assertSame([
            'headers' => [['value' => ''], ['value' => '']],
            'rows' => [
                ['cells' => [['value' => ''], ['value' => '']]],
            ],
        ], $definitions['table']['defaultData']);
    }

    public function test_editor_payload_is_indexed_and_contains_required_metadata(): void
    {
        $payload = BlogEditorBlockCatalog::forEditor();

        self::assertSame(range(0, count($payload) - 1), array_keys($payload));
        self::assertSame('paragraph', $payload[0]['type']);
        self::assertSame('cta', $payload[array_key_last($payload)]['type']);

        foreach ($payload as $definition) {
            self::assertArrayHasKey('type', $definition);
            self::assertArrayHasKey('label', $definition);
            self::assertArrayHasKey('icon', $definition);
            self::assertArrayHasKey('defaultData', $definition);
        }
    }
}
