<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use Closure;
use InvalidArgumentException;

use function trans_message;

final class ClarificationQuestionProjector
{
    private Closure $translator;

    public function __construct(?Closure $translator = null)
    {
        $this->translator = $translator ?? static fn (string $key): string => trans_message($key);
    }

    /** @param list<array<string,mixed>> $pages @return list<EstimateClarificationQuestion> */
    public function projectPages(array $pages): array
    {
        $groups = [];
        foreach ($pages as $page) {
            $pageNumber = is_int($page['page_number'] ?? null) ? $page['page_number'] : null;
            $pageSource = $this->pageSource($page, $pageNumber);
            $arbitration = is_array($page['document_arbitration'] ?? null) ? $page['document_arbitration'] : [];
            $pageQuestions = is_array($page['ai_questions'] ?? null)
                ? $page['ai_questions']
                : ((($arbitration['role'] ?? null) === 'arbiter' && is_array($arbitration['questions'] ?? null))
                    ? $arbitration['questions'] : []);
            foreach ($pageQuestions as $question) {
                if (! is_array($question) || ! is_string($question['code'] ?? null)) {
                    throw new InvalidArgumentException('estimate_clarification_projection_invalid');
                }
                $code = $question['code'];
                $groups[$code] ??= ['questions' => [], 'pages' => [], 'evidence' => [], 'sources' => [], 'authority' => 'corroboration'];
                $groups[$code]['questions'][] = $question;
                if ($pageNumber !== null) {
                    $groups[$code]['pages'][$pageNumber] = true;
                }
                $locator = is_array($question['source_locator'] ?? null) ? $question['source_locator'] : [];
                foreach (is_array($locator['evidence_refs'] ?? null) ? $locator['evidence_refs'] : [] as $ref) {
                    if (is_string($ref) && preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $ref) === 1) {
                        $groups[$code]['evidence'][$ref] = true;
                    }
                }
                if (($locator['authority'] ?? null) === 'explicit_document') {
                    $groups[$code]['authority'] = 'explicit_document';
                }
                $sources = is_array($locator['sources'] ?? null) ? $locator['sources'] : [];
                if ($pageSource !== null) {
                    $sources[] = $pageSource;
                }
                if (isset($locator['document_id']) || isset($locator['page_id']) || isset($locator['source_version'])) {
                    $sources[] = array_filter([
                        'document_id' => $locator['document_id'] ?? null,
                        'page_id' => $locator['page_id'] ?? null,
                        'page_number' => $locator['page_number'] ?? $pageNumber,
                        'source_version' => $locator['source_version'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null);
                }
                foreach ($sources as $source) {
                    if (is_array($source) && ! array_is_list($source)) {
                        $groups[$code]['sources'][json_encode($source, JSON_THROW_ON_ERROR)] = $source;
                    }
                }
            }
        }

        ksort($groups, SORT_STRING);
        $result = [];
        foreach ($groups as $code => $group) {
            $question = $this->authoritativeQuestion($group['questions']);
            $choices = [];
            foreach ($group['questions'] as $candidate) {
                foreach (is_array($candidate['choices'] ?? null) ? $candidate['choices'] : [] as $choice) {
                    if (is_string($choice) && trim($choice) !== '' && mb_strlen($choice) <= 160) {
                        $choices[mb_strtolower(trim($choice))] = trim($choice);
                    }
                }
            }
            if ($choices === [] || count($choices) > 8) {
                throw new InvalidArgumentException('estimate_clarification_choices_invalid');
            }
            $choiceObjects = array_map(
                static fn (string $choice): EstimateClarificationChoice => new EstimateClarificationChoice('select:'.hash('sha256', $choice), $choice),
                array_values($choices),
            );
            $choiceObjects[] = new EstimateClarificationChoice('other', ($this->translator)('estimate_generation.ai_questions.other'), 'other');
            $choiceObjects[] = new EstimateClarificationChoice('leave_unresolved', ($this->translator)('estimate_generation.ai_questions.leave_unresolved'), 'leave_unresolved');
            $pageNumbers = array_map('intval', array_keys($group['pages']));
            sort($pageNumbers, SORT_NUMERIC);
            $evidenceRefs = array_keys($group['evidence']);
            sort($evidenceRefs, SORT_STRING);
            $result[] = new EstimateClarificationQuestion(
                $code,
                is_string($question['subject'] ?? null) ? $question['subject'] : throw new InvalidArgumentException('estimate_clarification_subject_missing'),
                (string) ($question['reason'] ?? ''),
                (string) ($question['impact'] ?? ''),
                (string) ($question['recommendation'] ?? ''),
                $choiceObjects,
                array_filter([
                    'page_numbers' => $pageNumbers,
                    'evidence_refs' => $evidenceRefs,
                    'authority' => $group['authority'],
                    'sources' => array_values($group['sources']),
                ], static fn (mixed $value): bool => $value !== []),
            );
        }

        return array_slice($result, 0, 128);
    }

    /** @param array<string,mixed> $page @return array<string,mixed>|null */
    private function pageSource(array $page, ?int $pageNumber): ?array
    {
        $documentId = $page['document_id'] ?? null;
        $pageId = $page['page_id'] ?? null;
        $sourceVersion = $page['source_version'] ?? null;
        if (! is_int($documentId) || $documentId < 1
            || ! is_int($pageId) || $pageId < 1
            || $pageNumber === null
            || ! is_string($sourceVersion)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1) {
            return null;
        }

        return [
            'document_id' => $documentId,
            'page_id' => $pageId,
            'page_number' => $pageNumber,
            'source_version' => $sourceVersion,
        ];
    }

    /** @param list<array<string,mixed>> $questions @return array<string,mixed> */
    private function authoritativeQuestion(array $questions): array
    {
        foreach ($questions as $question) {
            if (($question['source_locator']['authority'] ?? null) === 'explicit_document') {
                return $question;
            }
        }

        return $questions[0] ?? throw new InvalidArgumentException('estimate_clarification_question_missing');
    }
}
