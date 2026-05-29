<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use PHPUnit\Framework\TestCase;

final class IntentRecognizerTest extends TestCase
{
    public function test_finance_summary_prompt_uses_budget_context(): void
    {
        $recognizer = new IntentRecognizer;

        $this->assertSame(
            'project_budget',
            $recognizer->recognize('Собери короткую сводку по финансам')
        );

        $this->assertSame(
            'project_budget',
            $recognizer->recognize('Есть ли перекос по финансам')
        );
    }
}
