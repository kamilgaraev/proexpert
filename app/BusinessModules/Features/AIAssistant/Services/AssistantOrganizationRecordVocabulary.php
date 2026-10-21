<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;

final class AssistantOrganizationRecordVocabulary
{
    public static function normalize(string $question, bool $maskProjectNames = false): string
    {
        preg_match_all('/(?:«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*")(*SKIP)(*F)|(?<![\pL\pN])(?:(?:(?:с|со)\s+)?всеми|по\s+всем|всех|все|вся|всю|всей|всё|весь|всего|всему|всем)(?![\pL\pN])\s+(?:наш(?:ими|и|им|их|а|у|ей|е|его|ему|ем)?\s+)?/iu', $question, $prefixes, PREG_OFFSET_CAPTURE);
        if ($prefixes[0] === [] || ! app()->bound('translator')) {
            return $question;
        }
        $phrases = self::phrases();
        foreach (array_reverse($prefixes[0]) as [$prefix, $offset]) {
            $before = substr($question, 0, $offset);
            $projectName = preg_match('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+)$/iu', $before) === 1
                && preg_match('/'.AssistantRequestProjectScope::CURRENT_PROJECT.'\s*:?\s*$/iu', $before) !== 1;
            if ($projectName && ! $maskProjectNames) {
                continue;
            }
            $start = $offset + strlen($prefix);
            $tail = substr($question, $start);
            preg_match_all('/(?<![\pL\pN])(?:нашей\s+)?организации(?![\pL\pN])/iu', $tail, $endings, PREG_OFFSET_CAPTURE);
            foreach (array_reverse($endings[0]) as [$ending, $endOffset]) {
                $phrase = trim(substr($tail, 0, $endOffset));
                if (preg_match('/[,.;:!?\r\n«»“”„"]/u', $phrase) === 1 || preg_match_all('/[\pL\pN_]+/u', $phrase) > 16) {
                    continue;
                }
                if (! self::matches($phrase, $phrases)) {
                    continue;
                }
                $length = strlen($prefix) + $endOffset + strlen($ending);
                $relation = preg_match('/^(?:с|со)\s+/iu', $prefix, $match) === 1 ? $match[0] : '';
                $question = substr_replace($question, $projectName ? 'текущий' : $relation.'все акты организации', $offset, $length);
                break;
            }
        }

        return $question;
    }

    public static function matchesEntityPhrase(string $phrase): bool
    {
        return preg_match('/^[\pL\pN_-]+(?:\s+[\pL\pN_-]+){0,15}$/u', $phrase) === 1
            && preg_match('/(?<![\pL\pN])(?:не|без|кроме|исключая)(?![\pL\pN])/iu', $phrase) !== 1
            && self::matches($phrase, self::phrases());
    }

    public static function subjectMentions(string $text): array
    {
        if (! app()->bound('translator')) {
            throw new AssistantReportScopeAmbiguous;
        }
        $catalog = app()->bound(AssistantDomainCatalog::class)
            ? app(AssistantDomainCatalog::class) : new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $translated = app('translator')->get('ai_assistant_facts.entities', [], 'ru');
        $labels = array_replace(is_array($translated) ? $translated : [], AssistantExtendedDomainRegistry::values('entityLabels'));
        $phrases = [];
        foreach ($catalog->all() as $definition) {
            foreach ($definition->entityTypes as $type) {
                $names = [$labels[$type] ?? null];
                if ($type === 'performance_act') {
                    $names[] = 'Акт выполненных работ';
                }
                foreach ($names as $name) {
                    if (is_string($name)) {
                        $phrases[self::identity($name)][$definition->domain.':'.$type] = ['domain' => $definition->domain, 'entity_type' => $type];
                    }
                }
            }
        }
        preg_match_all('/[\pL\pN_-]+/u', $text, $words, PREG_OFFSET_CAPTURE);
        $subjects = [];
        for ($offset = 0; $offset < count($words[0]); $offset++) {
            for ($length = min(16, count($words[0]) - $offset); $length > 0; $length--) {
                $matches = $phrases[self::identity(implode(' ', array_column(array_slice($words[0], $offset, $length), 0)))] ?? [];
                if ($matches === []) {
                    continue;
                }
                if (count($matches) !== 1) {
                    throw new AssistantReportScopeAmbiguous;
                }
                $start = $words[0][$offset][1];
                $end = $words[0][$offset + $length - 1];
                $subjects[] = ['offset' => $start, 'length' => $end[1] + strlen($end[0]) - $start, 'subject' => array_values($matches)[0]];
                $offset += $length - 1;
                break;
            }
        }

        return array_values($subjects);
    }

    private static function phrases(): array
    {
        if (! app()->bound('translator')) {
            return [];
        }
        $catalog = app()->bound(AssistantDomainCatalog::class)
            ? app(AssistantDomainCatalog::class) : new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $translated = app('translator')->get('ai_assistant_facts.entities', [], 'ru');
        $labels = array_replace(is_array($translated) ? $translated : [], AssistantExtendedDomainRegistry::values('entityLabels'));
        $phrases = [];
        foreach ($catalog->all() as $definition) {
            foreach ($definition->entityTypes as $type) {
                if (is_string($labels[$type] ?? null)) {
                    $phrases[self::identity($labels[$type])] = true;
                }
            }
        }

        return $phrases;
    }

    private static function matches(string $phrase, array $phrases): bool
    {
        for ($removed = 0; $removed <= 6; $removed++) {
            if (isset($phrases[self::identity($phrase)])) {
                return true;
            }
            if (preg_match('/^(?:[а-яё][а-яё-]*(?:ые|ие|ых|их|ым|им|ыми|ими|ая|яя|ую|юю|ой|ей|ое|ее|ого|его|ому|ему|ом|ем)|частично|полностью|еще|ещё|пока|не)\s+/iu', $phrase, $match) !== 1) {
                break;
            }
            $phrase = substr($phrase, strlen($match[0]));
        }

        return false;
    }

    private static function identity(string $phrase): string
    {
        preg_match_all('/[\pL\pN_]+/u', str_replace('ё', 'е', mb_strtolower($phrase)), $matches);

        return implode(' ', array_map(static function (string $word): string {
            if (mb_strlen($word) < 4 || str_contains($word, '_')) {
                return $word;
            }

            return preg_replace('/(?:иями|ями|ами|иям|иях|ов|ей|ия|ие|ий|ые|ое|ая|ую|ой|ый|ом|ем|ах|ях|ам|ям|ых|их|а|я|ы|и|у|ю|е|о|ь)$/u', '', $word) ?? $word;
        }, $matches[0]));
    }
}
