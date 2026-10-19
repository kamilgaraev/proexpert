<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInputInspector;
use PHPUnit\Framework\TestCase;

final class FactoryInputInspectorTest extends TestCase
{
    public function test_no_questions_does_not_mean_a_complete_estimate_or_permission_to_pay(): void
    {
        $result = FactoryInputInspector::summarize($this->native());
        self::assertSame('planning_required', $result['outcome']);
        self::assertFalse($result['estimate_complete']);
        self::assertFalse($result['paid_actions_enabled']);
        self::assertSame('0', $result['cost_units']);
    }

    public function test_questions_store_only_safe_refs_and_source_limitations_remain_a_separate_stop(): void
    {
        $native = $this->native();
        $native['total_questions'] = 51;
        $native['truncated'] = true;
        $native['limitations'] = ['questions_truncated', 'source_review_required'];
        $native['questions'] = [['key' => 'question:key', 'can_answer' => true, 'source_text' => 'never persist',
            'answer' => ['version' => str_repeat('a', 64), 'entity_id' => 'entity:beam', 'parameter' => 'height', 'other' => 'ignored']]];
        $result = FactoryInputInspector::summarize($native);
        self::assertSame('source_review_required', $result['outcome']);
        self::assertSame(51, $result['total_questions']);
        self::assertTrue($result['truncated']);
        self::assertSame(['key', 'version', 'entity_id', 'parameter', 'can_answer'], array_keys($result['question_refs'][0]));
        self::assertStringNotContainsString('never persist', json_encode($result, JSON_THROW_ON_ERROR));
        $native['limitations'] = ['questions_truncated'];
        self::assertSame('awaiting_input', FactoryInputInspector::summarize($native)['outcome']);
        $native['available'] = false;
        self::assertSame('unavailable', FactoryInputInspector::summarize($native)['outcome']);
    }

    private function native(): array
    {
        return ['available' => true, 'questions' => [], 'total_questions' => 0, 'truncated' => false, 'limitations' => []];
    }
}
