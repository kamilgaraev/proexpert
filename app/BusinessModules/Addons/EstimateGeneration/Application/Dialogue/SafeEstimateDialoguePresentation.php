<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use RuntimeException;

final readonly class SafeEstimateDialoguePresentation
{
    public const CONTRACT_VERSION = 'estimate-command:v1';

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function sanitize(array $payload): array
    {
        unset(
            $payload['provider'],
            $payload['provider_model'],
            $payload['model'],
            $payload['model_version'],
            $payload['reasoning'],
            $payload['raw_response'],
        );
        $payload['version'] = self::CONTRACT_VERSION;
        if (array_key_exists('explanation', $payload)) {
            $payload['explanation'] = $this->text($payload['explanation'], 8000);
        }
        foreach (['assumptions', 'questions'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            if (! is_array($payload[$field]) || ! array_is_list($payload[$field]) || count($payload[$field]) > 100) {
                throw new RuntimeException('estimate_generation.dialogue_presentation_invalid');
            }
            $payload[$field] = array_map(fn (mixed $value): string => $this->text($value, 1000), $payload[$field]);
        }

        return $payload;
    }

    private function text(mixed $value, int $maxLength): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) < 12 || mb_strlen($text) > $maxLength
            || preg_match('/\p{Cyrillic}/u', $text) !== 1
            || preg_match('/\b(?:provider|payload|dto|exception|sql|constraint|fallback|legacy|openai|timeweb|gpt|confidence|model[_ -]?version)\b/iu', $text) === 1
            || preg_match('/^нужно уточнить[.!]?$/iu', $text) === 1) {
            throw new RuntimeException('estimate_generation.dialogue_presentation_invalid');
        }

        return $text;
    }
}
