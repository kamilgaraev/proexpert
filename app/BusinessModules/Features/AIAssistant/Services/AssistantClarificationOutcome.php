<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantClarificationOutcome
{
    private function __construct(public ?string $missing) {}

    public static function fromText(string $text): ?self
    {
        $text = trim($text);
        $payload = $text;
        $fenced = false;
        $newline = strpos($text, "\n");
        if ($newline !== false && in_array(strtolower(trim(substr($text, 0, $newline))), ['```', '```json'], true)) {
            $payload = trim(substr($text, $newline + 1));
            if (str_ends_with($payload, '```')) {
                $payload = trim(substr($payload, 0, -3));
            }
            $fenced = true;
        }
        if (! str_starts_with($payload, '{') && ! str_starts_with($payload, '[')) {
            return null;
        }
        if (strlen($payload) > 16_384) {
            return new self(null);
        }
        try {
            $value = json_decode($payload, false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(null);
        }
        if ($value instanceof \stdClass && ($value->kind ?? null) === 'verified_rows') {
            return null;
        }
        if ($fenced || strlen($text) > 512 || ! $value instanceof \stdClass) {
            return new self(null);
        }
        $fields = get_object_vars($value);
        if (count($fields) !== 2 || ($fields['kind'] ?? null) !== 'clarification'
            || ! is_string($fields['missing'] ?? null) || ! in_array($fields['missing'], ['period', 'focus', 'goal'], true)) {
            return new self(null);
        }

        return new self($fields['missing']);
    }

    public function verification(): array
    {
        return ['text' => $this->missing === null ? trans_message('ai_assistant_facts.live_proof_required')
            : trans_message('ai_assistant.clarification_'.$this->missing),
            'replaced' => true, 'validation_status' => 'unverified', 'source_refs' => [], 'needs_clarification' => true];
    }
}
