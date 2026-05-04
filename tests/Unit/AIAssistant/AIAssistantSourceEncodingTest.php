<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AIAssistantSourceEncodingTest extends TestCase
{
    #[DataProvider('criticalSourceProvider')]
    public function test_critical_source_strings_do_not_contain_mojibake_markers(string $path): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$path);

        $this->assertIsString($contents);
        $this->assertDoesNotMatchRegularExpression(
            '/[\x{0080}-\x{009F}\x{0400}\x{0402}-\x{040F}\x{0450}\x{0452}-\x{045F}]/u',
            $contents,
            "{$path} contains mojibake markers"
        );
    }

    public function test_system_prompt_does_not_teach_fake_report_links(): void
    {
        $contents = file_get_contents(
            dirname(__DIR__, 3)
            .DIRECTORY_SEPARATOR
            .'app/BusinessModules/Features/AIAssistant/Services/ContextBuilder.php'
        );

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('реальный_pdf_url_из_данных', $contents);
        $this->assertStringNotContainsString('ТУТ_ССЫЛКА', $contents);
    }

    public static function criticalSourceProvider(): array
    {
        return [
            'controller fallbacks' => ['app/BusinessModules/Features/AIAssistant/Http/Controllers/AIAssistantController.php'],
            'orchestrator routing and payload strings' => ['app/BusinessModules/Features/AIAssistant/Services/AssistantTaskOrchestrator.php'],
            'assistant service fallbacks and policy' => ['app/BusinessModules/Features/AIAssistant/Services/AIAssistantService.php'],
        ];
    }
}
