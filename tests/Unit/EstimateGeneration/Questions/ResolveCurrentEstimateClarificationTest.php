<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use PHPUnit\Framework\TestCase;

final class ResolveCurrentEstimateClarificationTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_requirement_only_input_needs_no_observed_and_rejects_source_authority_or_future_shapes(): void
    {
        $input = ['schema_version' => 2, 'condition' => 'minimum_length', 'source_requirement_ref' => str_repeat('b', 64)];
        self::assertSame($input, ResolveCurrentEstimateClarification::workParameterInput($input));
        foreach ([[...$input, 'value' => '102'], [...$input, 'passed' => true], [...$input, 'schema_version' => 3],
            [...$input, 'schema_version' => '2'], [...$input, 'source_requirement_ref' => 'document:17']] as $invalid) {
            try {
                ResolveCurrentEstimateClarification::workParameterInput($invalid);
                self::fail('Untrusted requirement input must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_generation.question_response_invalid', $exception->getMessage());
            }
        }
    }

    public function test_existing_answer_request_accepts_exact_v2_and_keeps_legacy_six_fields(): void
    {
        $factory = new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en'));
        $base = ['response' => 'other', 'other' => 'Подтверждаю выбранный фрагмент.', 'idempotency_key' => 'request-v2-1',
            'expected_source_version' => self::SOURCE_VERSION, 'answer_fingerprint' => str_repeat('a', 64), 'document_ids' => [17]];
        $v2 = ['schema_version' => 2, 'condition' => 'minimum_length', 'source_requirement_ref' => str_repeat('b', 64)];
        $v1 = ['name' => 'Зазор', 'value' => '102', 'unit' => 'mm', 'basis' => 'assumption',
            'source_operand_ref' => str_repeat('c', 64), 'condition' => 'minimum_length'];
        foreach ([$v1, $v2] as $parameter) {
            $request = \App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimateClarificationRequest::create('/', 'POST', [...$base, 'parameter' => $parameter]);
            self::assertTrue($factory->make($request->all(), $request->rules())->passes());
        }
        foreach ([[...$v2, 'value' => '102'], [...$v2, 'schema_version' => '2'], [...$v2, 'schema_version' => 3],
            [...$v2, 'source_pins' => []], [...$v2, 'verified' => true]] as $parameter) {
            $request = \App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimateClarificationRequest::create('/', 'POST', [...$base, 'parameter' => $parameter]);
            self::assertTrue($factory->make($request->all(), $request->rules())->fails());
        }
    }

    public function test_work_parameter_input_is_closed_exact_decimal_and_has_no_source_authority_fields(): void
    {
        $parameter = ['name' => 'Свободный зазор B', 'value' => '102', 'unit' => 'mm', 'basis' => 'measurement',
            'source_operand_ref' => str_repeat('a', 64), 'condition' => 'minimum_length'];
        self::assertSame($parameter, ResolveCurrentEstimateClarification::workParameterInput($parameter));
        self::assertSame(null, ResolveCurrentEstimateClarification::workParameterInput(null));
        foreach ([
            [...$parameter, 'engineering_applicability' => 'passed'],
            [...$parameter, 'value' => 102], [...$parameter, 'value' => '1e2'],
            [...$parameter, 'value' => '0'], [...$parameter, 'unit' => 'count'],
            [...$parameter, 'basis' => 'document_verified'], [...$parameter, 'condition' => 'maximum_length'],
            [...$parameter, 'source_operand_ref' => 'document:7'], [...$parameter, 'name' => ' B '],
        ] as $invalid) {
            try {
                ResolveCurrentEstimateClarification::workParameterInput($invalid);
                self::fail('Malformed parameter must not enter the work answer.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_generation.question_response_invalid', $exception->getMessage());
            }
        }
    }

    public function test_large_ai_choice_set_is_bounded_at_questions_ai_and_bound_to_exact_fact(): void
    {
        $resolver = new ResolveCurrentEstimateClarification($this->projector());
        $options = array_map(static fn (int $index): array => [
            'value' => 'select:fact:wall-material:'.$index,
            'fact_id' => 'fact:wall-material',
            'label' => 'Вариант '.$index,
            'evidence_ids' => ['evidence:1'],
        ], range(1, 12));
        $questions = [[
            'conflict_id' => 'conflict:wall-material',
            'text' => 'Какой материал наружных стен использовать?',
            'fact_ids' => ['fact:wall-material'],
            'evidence_ids' => ['evidence:1'],
            'source_locator' => ['page_numbers' => [4]],
            'options' => $options,
        ]];
        $snapshot = $this->snapshot();

        $first = $resolver->resolveAll($questions, 'sha256:'.str_repeat('c', 64), $snapshot, str_repeat('b', 64));
        $second = $resolver->resolveAll($questions, 'sha256:'.str_repeat('c', 64), $snapshot, str_repeat('d', 64));

        self::assertCount(1, $first);
        self::assertCount(10, $first[0]->question->choices);
        self::assertSame('fact:wall-material', $first[0]->targetFactId);
        self::assertSame(self::SOURCE_VERSION, $first[0]->sourceVersion);
        self::assertSame([4], $first[0]->question->sourceLocator['page_numbers']);
        self::assertSame('other', $first[0]->question->choices[8]->kind);
        self::assertSame('leave_unresolved', $first[0]->question->choices[9]->kind);
        self::assertNotSame($first[0]->answerFingerprint, $second[0]->answerFingerprint);
    }

    private function projector(): ProjectUnderstandingQuestionProjector
    {
        return new ProjectUnderstandingQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
    }

    private function snapshot(): ProjectModelSnapshot
    {
        $fact = new Fact(
            'fact:wall-material', 10, 20, 40, self::SOURCE_VERSION, 'entity:wall', 'wall_material', null,
            null, 0.0, 'unresolved', 'unresolved', ['evidence:1'],
        );
        $evidence = new Evidence(
            'evidence:1', 10, 20, 40, self::SOURCE_VERSION, 'document:7', 'document', 4,
        );

        return new ProjectModelSnapshot([], [$fact], [$evidence], []);
    }
}
