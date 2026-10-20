<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantOrganizationRecordVocabulary
{
    public static function normalize(string $question, bool $maskProjectNames = false): string
    {
        preg_match_all('/(?:«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*")(*SKIP)(*F)|(?<![\pL\pN])(?:по\s+всем|всех|все|вся|всю|всей|всё|весь|всего|всему|всем)(?![\pL\pN])\s+(?:наш(?:и|им|их|а|у|ей|е|его|ему|ем)?\s+)?/iu', $question, $prefixes, PREG_OFFSET_CAPTURE);
        if ($prefixes[0] === [] || ! app()->bound('translator')) {
            return $question;
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
        foreach (array_reverse($prefixes[0]) as [$prefix, $offset]) {
            $projectName = preg_match('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?:\s*:\s*|\s+)$/iu', substr($question, 0, $offset)) === 1;
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
                $question = substr_replace($question, $projectName ? 'текущий' : 'все акты организации', $offset, $length);
                break;
            }
        }

        return $question;
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
