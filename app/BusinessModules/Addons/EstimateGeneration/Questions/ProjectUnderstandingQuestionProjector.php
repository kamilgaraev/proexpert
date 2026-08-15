<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use Closure;
use InvalidArgumentException;

use function trans_message;

final class ProjectUnderstandingQuestionProjector
{
    private const MAX_AI_CHOICES = 8;

    private Closure $translator;

    public function __construct(?Closure $translator = null)
    {
        $this->translator = $translator ?? static fn (string $key): string => trans_message($key);
    }

    /** @param list<mixed> $questions @return list<EstimateClarificationQuestion> */
    public function project(array $questions, string $understandingSourceVersion): array
    {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $understandingSourceVersion) !== 1) {
            throw new InvalidArgumentException('estimate_clarification_source_version_invalid');
        }

        $projected = [];
        foreach ($questions as $question) {
            if (! is_array($question) || array_is_list($question)) {
                continue;
            }
            try {
                $projected[] = $this->question($question, $understandingSourceVersion);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $projected;
    }

    /** @param array<string,mixed> $question */
    private function question(array $question, string $understandingSourceVersion): EstimateClarificationQuestion
    {
        $identity = $question['conflict_id'] ?? $question['code'] ?? null;
        $text = $question['text'] ?? $question['subject'] ?? null;
        if (! is_string($identity) || trim($identity) === '' || strlen($identity) > 240
            || ! is_string($text) || trim($text) === '' || mb_strlen($text) > 160) {
            throw new InvalidArgumentException('estimate_clarification_question_invalid');
        }

        $factIds = $this->boundedIds($question['fact_ids'] ?? []);
        $evidenceIds = $this->boundedIds($question['evidence_ids'] ?? []);
        $choices = [];
        $seen = [];
        foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
            if (count($choices) >= self::MAX_AI_CHOICES || ! is_array($option) || array_is_list($option)) {
                continue;
            }
            $kind = $option['value'] ?? null;
            if (in_array($kind, ['other', 'leave_unresolved'], true)) {
                continue;
            }
            $label = $option['label'] ?? null;
            $factId = $option['fact_id'] ?? null;
            $value = is_string($kind) && trim($kind) !== ''
                ? trim($kind)
                : (is_string($factId) && trim($factId) !== '' ? 'select:'.trim($factId) : null);
            if (! is_string($label) || ! is_string($value) || strlen($value) > 160 || isset($seen[$value])) {
                continue;
            }
            $choices[] = new EstimateClarificationChoice($value, trim($label));
            $seen[$value] = true;
        }
        $choices[] = new EstimateClarificationChoice(
            'other',
            ($this->translator)('estimate_generation.ai_questions.other'),
            'other',
        );
        $choices[] = new EstimateClarificationChoice(
            'leave_unresolved',
            ($this->translator)('estimate_generation.ai_questions.leave_unresolved'),
            'leave_unresolved',
        );

        $reason = $this->text($question['reason'] ?? null, $text);
        $impact = $this->text($question['impact'] ?? null, $text);
        $recommendation = $this->text($question['recommendation'] ?? null, $text);

        return new EstimateClarificationQuestion(
            code: 'project_question_'.substr(hash('sha256', trim($identity)), 0, 32),
            subject: trim($text),
            reason: $reason,
            impact: $impact,
            recommendation: $recommendation,
            choices: $choices,
            sourceLocator: [
                'conflict_id' => trim($identity),
                'understanding_source_version' => $understandingSourceVersion,
                'fact_ids' => $factIds,
                'evidence_ids' => $evidenceIds,
            ],
        );
    }

    private function text(mixed $candidate, string $fallback): string
    {
        return is_string($candidate) && trim($candidate) !== '' && mb_strlen($candidate) <= 500
            ? trim($candidate)
            : trim($fallback);
    }

    /** @return list<string> */
    private function boundedIds(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        return array_values(array_slice(array_unique(array_filter(
            $items,
            static fn (mixed $item): bool => is_string($item) && trim($item) !== '' && strlen($item) <= 160,
        )), 0, 64));
    }
}
