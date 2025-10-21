<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\Models\WorkType;
use App\Models\WorkTypeMatchingDictionary;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WorkTypeMatchingService
{
    public function findMatches(string $importedText, int $organizationId, int $limit = 5): Collection
    {
        $normalizedText = $this->normalize($importedText);
        
        $historicalMatch = $this->historicalMatch($normalizedText, $organizationId);
        if ($historicalMatch && $historicalMatch['confidence'] >= 90) {
            return collect([$historicalMatch]);
        }
        
        $exactMatch = $this->exactMatch($normalizedText, $organizationId);
        if ($exactMatch) {
            return collect([$exactMatch]);
        }
        
        $codeMatch = $this->codeMatch($importedText, $organizationId);
        if ($codeMatch) {
            return collect([$codeMatch]);
        }
        
        $fuzzyMatches = $this->fuzzyMatch($normalizedText, $organizationId, $limit);
        
        if ($historicalMatch) {
            $fuzzyMatches->prepend($historicalMatch);
        }
        
        return $fuzzyMatches->take($limit);
    }

    public function exactMatch(string $normalizedText, int $organizationId): ?array
    {
        $workType = WorkType::where('organization_id', $organizationId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalizedText)])
            ->first();
        
        if ($workType) {
            return [
                'work_type' => $workType,
                'confidence' => 100,
                'method' => 'exact',
            ];
        }
        
        return null;
    }

    public function codeMatch(string $importedText, int $organizationId): ?array
    {
        if (preg_match('/([А-ЯA-Z]{2,4}\d{2}-\d{2}-\d{3}(-\d{2})?)/u', $importedText, $matches)) {
            $code = $matches[1];
            
            $workType = WorkType::where('organization_id', $organizationId)
                ->where('code', $code)
                ->first();
            
            if ($workType) {
                return [
                    'work_type' => $workType,
                    'confidence' => 95,
                    'method' => 'code',
                ];
            }
        }
        
        return null;
    }

    public function fuzzyMatch(string $normalizedText, int $organizationId, int $limit = 5): Collection
    {
        $workTypes = WorkType::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get(['id', 'name', 'code', 'category']);
        
        $results = collect();
        
        foreach ($workTypes as $workType) {
            $similarity = $this->calculateSimilarity($normalizedText, mb_strtolower($workType->name));
            
            if ($similarity > 60) {
                $results->push([
                    'work_type' => $workType,
                    'confidence' => $similarity,
                    'method' => 'fuzzy',
                ]);
            }
        }
        
        return $results->sortByDesc('confidence')->values()->take($limit);
    }

    public function historicalMatch(string $normalizedText, int $organizationId): ?array
    {
        $match = WorkTypeMatchingDictionary::where('organization_id', $organizationId)
            ->where('normalized_text', $normalizedText)
            ->where('is_confirmed', true)
            ->orderByDesc('usage_count')
            ->first();
        
        if ($match) {
            return [
                'work_type' => $match->workType,
                'confidence' => min($match->match_confidence + 5, 100),
                'method' => 'historical',
            ];
        }
        
        return null;
    }

    public function recordMatch(
        string $importedText,
        string $normalizedText,
        int $workTypeId,
        int $organizationId,
        ?int $userId = null,
        float $confidence = 100,
        bool $isConfirmed = false
    ): WorkTypeMatchingDictionary {
        $existing = WorkTypeMatchingDictionary::where('organization_id', $organizationId)
            ->where('normalized_text', $normalizedText)
            ->where('work_type_id', $workTypeId)
            ->first();
        
        if ($existing) {
            $existing->incrementUsage();
            if ($isConfirmed) {
                $existing->confirm();
            }
            return $existing;
        }
        
        return WorkTypeMatchingDictionary::create([
            'organization_id' => $organizationId,
            'imported_text' => $importedText,
            'normalized_text' => $normalizedText,
            'work_type_id' => $workTypeId,
            'matched_by_user_id' => $userId,
            'match_confidence' => $confidence,
            'usage_count' => 1,
            'is_confirmed' => $isConfirmed,
        ]);
    }

    public function suggestNew(string $importedText): array
    {
        return [
            'action' => 'create_new',
            'suggested_name' => $this->normalize($importedText),
            'original_text' => $importedText,
        ];
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        
        $text = preg_replace('/\s+/', ' ', $text);
        
        $text = trim($text);
        
        $prefixesToRemove = [
            '/^работы по\s+/ui',
            '/^выполнение\s+работ\s+по\s+/ui',
            '/^монтаж\s+и\s+демонтаж\s+/ui',
        ];
        
        foreach ($prefixesToRemove as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        $text = preg_replace('/\s*\(.*?\)\s*$/', '', $text);
        
        return $text;
    }

    protected function calculateSimilarity(string $str1, string $str2): float
    {
        $levenshtein = levenshtein($str1, $str2);
        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        
        if ($maxLen == 0) {
            return 100;
        }
        
        $levenshteinSimilarity = (1 - $levenshtein / $maxLen) * 100;
        
        similar_text($str1, $str2, $percent);
        
        $jaccardSimilarity = $this->jaccardSimilarity($str1, $str2) * 100;
        
        $keywordBonus = $this->keywordBonus($str1, $str2);
        
        $finalScore = (
            $levenshteinSimilarity * 0.3 +
            $percent * 0.3 +
            $jaccardSimilarity * 0.3 +
            $keywordBonus * 0.1
        );
        
        return round($finalScore, 2);
    }

    protected function jaccardSimilarity(string $str1, string $str2): float
    {
        $words1 = collect(explode(' ', $str1));
        $words2 = collect(explode(' ', $str2));
        
        $intersection = $words1->intersect($words2)->count();
        $union = $words1->merge($words2)->unique()->count();
        
        return $union > 0 ? $intersection / $union : 0;
    }

    protected function keywordBonus(string $str1, string $str2): float
    {
        $keywords = [
            'монтаж', 'демонтаж', 'установка', 'укладка', 'прокладка',
            'штукатурка', 'покраска', 'бетонирование', 'кладка',
            'кабель', 'труба', 'плитка', 'гипсокартон', 'окно', 'дверь',
            'стена', 'пол', 'потолок', 'фасад', 'кровля',
        ];
        
        $bonus = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($str1, $keyword) && str_contains($str2, $keyword)) {
                $bonus += 10;
            }
        }
        
        return min($bonus, 30);
    }
}

