<?php

declare(strict_types=1);

namespace Tests\Unit\KnowledgeHub;

use App\BusinessModules\Features\KnowledgeHub\Services\KnowledgeHubContentSanitizer;
use PHPUnit\Framework\TestCase;

class KnowledgeHubContentSanitizerTest extends TestCase
{
    public function test_it_removes_scripts_handlers_and_unsafe_links(): void
    {
        $clean = KnowledgeHubContentSanitizer::clean(
            '<h2 onclick="alert(1)" id="safe title">Раздел</h2>'
            .'<p>Текст<script>alert(1)</script></p>'
            .'<a href="javascript:alert(1)" onclick="alert(2)">опасная ссылка</a>'
            .'<a href="https://prohelper.pro/help" target="_blank">безопасная ссылка</a>'
            .'<iframe src="https://example.com"></iframe>',
        );

        self::assertStringContainsString('<h2 id="safetitle">Раздел</h2>', $clean);
        self::assertStringContainsString('<p>Текст</p>', $clean);
        self::assertStringContainsString('<a>опасная ссылка</a>', $clean);
        self::assertStringContainsString(
            '<a href="https://prohelper.pro/help" target="_blank" rel="noopener noreferrer">безопасная ссылка</a>',
            $clean,
        );
        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('iframe', $clean);
    }

    public function test_it_keeps_allowed_article_markup(): void
    {
        $clean = KnowledgeHubContentSanitizer::clean(
            '<h2>Профиль</h2><p><strong>Заполните</strong> данные.</p><ul><li>Проверить роль</li></ul>',
        );

        self::assertStringContainsString('<h2>Профиль</h2>', $clean);
        self::assertStringContainsString('<strong>Заполните</strong>', $clean);
        self::assertStringContainsString('<ul><li>Проверить роль</li></ul>', $clean);
    }
}
