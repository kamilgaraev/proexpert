<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantClarificationOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantClarificationOutcomeTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_only_closed_missing_values_render_server_questions_without_claims(): void
    {
        foreach (['period', 'focus', 'goal'] as $missing) {
            $outcome = AssistantClarificationOutcome::fromText(json_encode(['kind' => 'clarification', 'missing' => $missing], JSON_THROW_ON_ERROR));
            self::assertNotNull($outcome);
            self::assertSame($missing, $outcome->missing);
            self::assertSame(['text' => trans_message('ai_assistant.clarification_'.$missing), 'replaced' => true,
                'validation_status' => 'unverified', 'source_refs' => [], 'needs_clarification' => true], $outcome->verification());
        }
    }

    public static function rejectedEnvelopes(): array
    {
        return [
            'unknown-kind' => ['{"kind":"answer","missing":"period"}'],
            'unknown-slot' => ['{"kind":"clarification","missing":"project_id"}'],
            'missing-slot' => ['{"kind":"clarification"}'],
            'missing-kind' => ['{"missing":"period"}'],
            'null-slot' => ['{"kind":"clarification","missing":null}'],
            'numeric-slot' => ['{"kind":"clarification","missing":100}'],
            'free-question' => ['{"kind":"clarification","missing":"period","question":"Бюджет 100 рублей?"}'],
            'free-value' => ['{"kind":"clarification","missing":"period","value":"2026-10-01"}'],
            'financial-claim' => ['{"kind":"clarification","missing":"goal","amount":100}'],
            'nested-plan' => ['{"kind":"clarification","missing":"goal","extra":{"kind":"verified_rows"}}'],
            'malformed' => ['{"kind":"clarification","missing":"period"'],
            'trailing-claim' => ['{"kind":"clarification","missing":"period"} Бюджет 100 рублей.'],
            'list' => ['[{"kind":"clarification","missing":"period"}]'],
            'fenced' => ["```json\n{\"kind\":\"clarification\",\"missing\":\"period\"}\n```"],
            'oversized' => ['{"kind":"clarification","missing":"'.str_repeat('x', 600).'"}'],
            'parse-budget' => ['{"kind":"clarification","missing":"'.str_repeat('x', 17_000).'"}'],
        ];
    }

    #[DataProvider('rejectedEnvelopes')]
    public function test_invalid_structured_outcomes_never_render_model_fields(string $text): void
    {
        $outcome = AssistantClarificationOutcome::fromText($text);
        self::assertNotNull($outcome);
        self::assertNull($outcome->missing);
        self::assertSame(trans_message('ai_assistant_facts.live_proof_required'), $outcome->verification()['text']);
        self::assertSame([], $outcome->verification()['source_refs']);
    }

    public function test_plain_questions_and_existing_verified_rows_are_not_clarification_outcomes(): void
    {
        foreach (['За какой период?', 'Показатель подтверждён?', '{"kind":"verified_rows","result_sets":[]}',
            "```json\n{\"kind\":\"verified_rows\",\"result_sets\":[]}\n```"] as $text) {
            self::assertNull(AssistantClarificationOutcome::fromText($text));
        }
    }
}
