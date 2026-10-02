<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AssistantTextSearch
{
    public static function apply(Builder $query, array $columns, string $term, bool $transliterate = true): void
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', str_replace('ё', 'е', mb_strtolower(trim($term))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            $tokens = [$term];
        }
        foreach ($tokens as $token) {
            $stem = mb_strlen($token) > 4 && preg_match('/^[а-я]+$/u', $token) === 1
                ? (preg_replace('/(?:ого|его|ому|ему|ыми|ими|иях|ая|яя|ой|ую|юю|ые|ых|ие|ий|ии|ям|ей|ам|ов|ах|ях|а|я|ы|и|у|е)$/u', '', $token) ?? $token) : $token;
            $terms = array_unique([$stem, ...($transliterate ? [Str::transliterate($stem)] : [])]);
            $query->where(static function (Builder $search) use ($columns, $terms): void {
                foreach ($columns as $column) {
                    foreach ($terms as $word) {
                        $search->orWhereRaw("replace(lower({$column}), 'ё', 'е') LIKE ?", ['%'.addcslashes($word, '%_\\').'%']);
                    }
                }
            });
        }
    }
}
