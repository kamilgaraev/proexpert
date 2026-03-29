<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Services\Blog\BlogDocumentRenderer;
use PHPUnit\Framework\TestCase;

class BlogDocumentRendererTest extends TestCase
{
    public function test_it_renders_builder_list_blocks_with_value_payloads(): void
    {
        $renderer = new BlogDocumentRenderer();

        $html = $renderer->render([
            [
                'type' => 'list',
                'data' => [
                    'style' => 'unordered',
                    'items' => [
                        ['value' => 'Первый пункт'],
                        ['value' => 'Второй пункт'],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>Первый пункт</li>', $html);
        self::assertStringContainsString('<li>Второй пункт</li>', $html);
    }

    public function test_it_renders_builder_table_blocks_with_nested_cells(): void
    {
        $renderer = new BlogDocumentRenderer();

        $html = $renderer->render([
            [
                'type' => 'table',
                'data' => [
                    'headers' => [
                        ['value' => 'Этап'],
                        ['value' => 'Результат'],
                    ],
                    'rows' => [
                        [
                            'cells' => [
                                ['value' => 'Подготовка'],
                                ['value' => 'План согласован'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('<th>Этап</th>', $html);
        self::assertStringContainsString('<th>Результат</th>', $html);
        self::assertStringContainsString('<td>Подготовка</td>', $html);
        self::assertStringContainsString('<td>План согласован</td>', $html);
    }
}
