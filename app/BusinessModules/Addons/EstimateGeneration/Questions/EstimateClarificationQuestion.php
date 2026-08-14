<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use InvalidArgumentException;

final readonly class EstimateClarificationQuestion
{
    /** @param list<EstimateClarificationChoice> $choices @param array<string,mixed> $sourceLocator */
    public function __construct(
        public string $code,
        public string $subject,
        public string $reason,
        public string $impact,
        public string $recommendation,
        public array $choices,
        public array $sourceLocator,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $code) !== 1
            || trim($subject) === '' || mb_strlen($subject) > 160
            || count($choices) < 3 || count($choices) > 10
            || $sourceLocator === [] || array_is_list($sourceLocator)) {
            throw new InvalidArgumentException('estimate_clarification_question_invalid');
        }
        foreach ([$subject, $reason, $impact, $recommendation] as $text) {
            self::assertBusinessText($text);
        }
        foreach ($choices as $choice) {
            if (! $choice instanceof EstimateClarificationChoice) {
                throw new InvalidArgumentException('estimate_clarification_question_invalid');
            }
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'subject' => $this->subject,
            'reason' => $this->reason,
            'impact' => $this->impact,
            'recommendation' => $this->recommendation,
            'choices' => array_map(static fn (EstimateClarificationChoice $choice): array => $choice->toArray(), $this->choices),
            'source_locator' => $this->sourceLocator,
        ];
    }

    private static function assertBusinessText(string $text): void
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '' || mb_strlen($text) > 500 || preg_match('/[А-Яа-яЁё]/u', $text) !== 1
            || preg_match('/needs? clarification|нужно уточнить|openai|timeweb|provider|fallback|payload|exception|timeout/ui', $normalized) === 1) {
            throw new InvalidArgumentException('estimate_clarification_business_text_invalid');
        }
    }
}
