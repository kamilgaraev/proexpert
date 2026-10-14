<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantProjectClarificationIntent
{
    public static function query(string $query, array $payload, array $conversationContext): string
    {
        $previous = $conversationContext['last_request'] ?? null;
        if (! is_array($previous) || ($previous['allow_actions'] ?? false) || ($payload['allow_actions'] ?? false)
            || in_array($previous['goal'] ?? null, ['act', 'wizard'], true)
            || in_array($previous['desired_mode'] ?? null, ['act', 'wizard'], true)) { return $query; }
        $message = $previous['message'] ?? null;
        if (! is_string($message) || trim($message) === '' || mb_strlen($message) > 4000
            || preg_match('/создай|удали|измени|оплати|отправь|передай|подпиши|согласуй|утверди|одобри/iu', $message) === 1) { return $query; }
        $normalized = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        if (mb_strlen($normalized) > 180
            || preg_match('/^(?:по|для|в)\s+(?:проект[а-яё]*|объект[а-яё]*)\s+[\p{L}\p{N}\s«»"().-]+[.!?]?$/iu', $normalized) !== 1
            || preg_match('/создай|удали|измени|оплати|отправь|передай|подпиши|согласуй|утверди|одобри|покажи|сколько|какие|что\s|и\s+(?:ещ[её]|также)/iu', $normalized) === 1) { return $query; }
        $refs = $payload['context']['entity_refs'] ?? [];
        if (! is_array($refs)) { return $query; }
        $projects = array_values(array_filter($refs, static fn ($ref): bool => is_array($ref) && ($ref['type'] ?? null) === 'project'));
        if (count($projects) !== 1 || ! is_int($projects[0]['id'] ?? null) || $projects[0]['id'] < 1) { return $query; }

        return trim($message)."\n".$normalized;
    }
}
