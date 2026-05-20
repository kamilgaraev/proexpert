<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportIntentResolver;
use PHPUnit\Framework\TestCase;

final class AssistantReportPromptDatasetTest extends TestCase
{
    public function test_report_prompt_dataset_resolves_expected_intents(): void
    {
        $dataset = $this->loadDataset();
        $resolver = new AssistantReportIntentResolver;

        foreach ($dataset as $case) {
            $result = $resolver->resolve($case['prompt']);

            if (isset($case['expected_status'])) {
                $this->assertSame($case['expected_status'], $result['status'], $case['name']);

                continue;
            }

            $this->assertSame('matched', $result['status'], $case['name']);
            $this->assertSame($case['expected_report_id'], $result['definition']->id, $case['name']);
        }
    }

    /**
     * @return array<int, array{name: string, prompt: string, expected_report_id?: string, expected_status?: string}>
     */
    private function loadDataset(): array
    {
        $path = __DIR__.'/../../../Fixtures/AIAssistant/report_prompts.ru.json';
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
