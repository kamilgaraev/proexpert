<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives\NormativeSearchProfileData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class NormativeCandidateSearchService
{
    private const MIN_POOL_SIZE = 300;
    private const MIN_PROFILE_FALLBACK_POOL_SIZE = 30;

    public function __construct(
        private readonly WorkIntentClassifier $workIntentClassifier,
        private readonly NormativeSearchProfileCatalog $searchProfileCatalog,
    ) {}

    /**
     * @param array<string, mixed> $workItem
     * @param array<string, mixed> $context
     * @param array<int, string> $tokens
     * @return Collection<int, EstimateNorm>
     */
    public function search(
        EstimateDatasetVersion $version,
        array $workItem,
        array $context,
        array $tokens,
        int $limit
    ): Collection {
        $intent = $this->workIntentClassifier->classify($workItem, $context);
        $profile = $this->searchProfileCatalog->forIntentData($intent);
        $pool = $this->queryPool($version, $workItem, $tokens, $intent, $profile, max($limit * 6, self::MIN_POOL_SIZE));

        $ranked = $pool
            ->sortByDesc(fn (EstimateNorm $norm): float => $this->scorePoolCandidate($norm, $workItem, $tokens, $intent, $profile))
            ->values();

        $compatible = $this->compatibleCandidates($ranked, (string) ($workItem['unit'] ?? ''));
        if ($compatible->isNotEmpty()) {
            $ranked = $compatible;
        }

        return $ranked
            ->take($limit);
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, EstimateNorm>
     */
    private function queryPool(
        EstimateDatasetVersion $version,
        array $workItem,
        array $tokens,
        WorkIntentData $intent,
        NormativeSearchProfileData $profile,
        int $poolLimit
    ): Collection
    {
        $tokens = $this->expandedTokens($tokens, $intent, $profile);
        $pool = $this->executePoolQuery($version, $tokens, $intent, $profile, $poolLimit);

        if ($this->shouldExpandByProfile($pool, $tokens, $profile, $poolLimit)) {
            $profilePool = $this->executePoolQuery($version, [], $intent, $profile, $poolLimit);
            $pool = $pool
                ->merge($profilePool)
                ->unique(static fn (EstimateNorm $norm): int => (int) $norm->id)
                ->take($poolLimit)
                ->values();
        }

        return $pool
            ->reject(fn (EstimateNorm $norm): bool => $this->forbiddenDomainCandidate($norm, $workItem, $intent))
            ->values();
    }

    /**
     * @param array<int, string> $tokens
     * @return Collection<int, EstimateNorm>
     */
    private function executePoolQuery(
        EstimateDatasetVersion $version,
        array $tokens,
        WorkIntentData $intent,
        NormativeSearchProfileData $profile,
        int $poolLimit
    ): Collection
    {
        $query = EstimateNorm::query()
            ->with(['collection', 'section'])
            ->whereHas('collection', static function (Builder $query) use ($version): void {
                $query->where('dataset_version_id', $version->id);
            });

        if ($tokens !== []) {
            $query->where(function (Builder $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%' . mb_strtolower($token) . '%';
                    $query->orWhereRaw('LOWER(code) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(section_name, \'\')) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(CAST(work_composition AS TEXT)) LIKE ?', [$like]);
                }
            });
        }

        if ($profile->allowedSectionPrefixes !== []) {
            $query->where(function (Builder $query) use ($profile): void {
                $query->whereNull('section_code');

                foreach ($profile->allowedSectionPrefixes as $prefix) {
                    $query->orWhere('section_code', 'like', $prefix . '%');
                }
            });
        }

        foreach (array_values(array_unique([...$intent->forbiddenSectionPrefixes, ...$profile->forbiddenSectionPrefixes])) as $prefix) {
            $query->where(function (Builder $query) use ($prefix): void {
                $query->whereNull('section_code')
                    ->orWhere('section_code', 'not like', $prefix . '%');
            });
        }

        return $query
            ->orderBy('code')
            ->limit($poolLimit)
            ->get();
    }

    /**
     * @param Collection<int, EstimateNorm> $pool
     * @param array<int, string> $tokens
     */
    private function shouldExpandByProfile(Collection $pool, array $tokens, NormativeSearchProfileData $profile, int $poolLimit): bool
    {
        if ($tokens === [] || $pool->count() >= min(self::MIN_PROFILE_FALLBACK_POOL_SIZE, $poolLimit)) {
            return false;
        }

        return $profile->allowedSectionPrefixes !== [] || $profile->requiredTerms !== [] || $profile->synonymTerms !== [];
    }

    /**
     * @param array<string, mixed> $workItem
     */
    private function forbiddenDomainCandidate(EstimateNorm $norm, array $workItem, WorkIntentData $intent): bool
    {
        $candidateText = mb_strtolower(trim(implode(' ', array_filter([
            (string) ($norm->code ?? ''),
            (string) ($norm->name ?? ''),
            (string) ($norm->section_name ?? ''),
            (string) ($norm->section?->name ?? ''),
        ]))));
        $workText = mb_strtolower(trim(implode(' ', array_filter([
            (string) ($workItem['name'] ?? ''),
            (string) ($workItem['description'] ?? ''),
            (string) ($workItem['work_category'] ?? ''),
            (string) ($workItem['normative_search_text'] ?? ''),
        ]))));

        if ($this->containsAny($candidateText, ['кран портальн', 'портальный кран', 'кран козлов']) && !$this->containsAny($workText, ['кран', 'подъемн'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['железнодорож', 'земляное полотно']) && !$this->containsAny($workText, ['железнодорож', 'рельс', 'путь'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['бурени', 'скважин']) && !$this->containsAny($workText, ['бурени', 'скважин'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['взрыв', 'взрываем']) && !$this->containsAny($workText, ['взрыв', 'взрываем'])) {
            return true;
        }

        if ($this->containsAny($candidateText, ['шпунт']) && !$this->containsAny($workText, ['шпунт'])) {
            return true;
        }

        if (
            $this->containsAny($candidateText, ['водопроводн арматур', 'арматур водопровод'])
            && !in_array($intent->system, ['water_supply', 'sewerage'], true)
            && $intent->action !== 'pipe_layout'
        ) {
            return true;
        }

        if (
            $this->containsAny($candidateText, ['землян', 'разработк грунт', 'котлован', 'транше'])
            && !in_array($intent->scope, ['foundation', 'site'], true)
            && !in_array($intent->action, ['excavation', 'backfill'], true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, string>
     */
    private function expandedTokens(array $tokens, WorkIntentData $intent, NormativeSearchProfileData $profile): array
    {
        $expanded = [
            ...$profile->requiredTerms,
            ...$profile->synonymTerms,
        ];

        foreach ($tokens as $token) {
            array_push($expanded, ...$this->tokenVariants($token));
        }

        if ($intent->action === 'cable_installation') {
            $expanded[] = 'кабел';
            $expanded[] = 'проклад';
        }

        if ($intent->action === 'pipe_layout') {
            $expanded[] = 'труб';
            $expanded[] = 'проклад';
        }

        if ($intent->action === 'insulation') {
            $expanded[] = 'утепл';
        }

        return array_values(array_unique(array_filter(
            $expanded,
            static fn (string $token): bool => mb_strlen($token) >= 3
        )));
    }

    /**
     * @return array<int, string>
     */
    private function tokenVariants(string $token): array
    {
        $normalized = mb_strtolower(trim($token));
        $variants = [$normalized];
        $code = $this->normalizeNormCodeToken($normalized);

        if ($code !== null) {
            $variants[] = $code;
        }

        foreach ($this->constructionStems() as $stem) {
            if (str_contains($normalized, $stem)) {
                $variants[] = $stem;
            }
        }

        $roughStem = $this->roughRussianStem($normalized);
        if ($roughStem !== null) {
            $variants[] = $roughStem;
        }

        return array_values(array_unique(array_filter($variants, static fn (string $value): bool => $value !== '')));
    }

    private function normalizeNormCodeToken(string $token): ?string
    {
        $token = preg_replace('/^(гэснмр|гэснм|гэснп|гэснр|гэсн|фснб|фер|тер|тсн)[\s:№#-]*/u', '', $token) ?? $token;
        $token = str_replace(['.', '_', ' '], '-', $token);
        $token = preg_replace('/[^0-9-]/', '', $token) ?? '';
        $token = preg_replace('/-+/', '-', trim($token, '-')) ?? '';

        if (!preg_match('/^\d{2}-\d{2}-\d{3}(?:-\d{2})?$/', $token)) {
            return null;
        }

        return $token;
    }

    /**
     * @return array<int, string>
     */
    private function constructionStems(): array
    {
        return [
            'армиров',
            'арматур',
            'бетон',
            'бетонир',
            'благоустрой',
            'водоснаб',
            'водосток',
            'воздуховод',
            'выемк',
            'выключател',
            'гидроизоляц',
            'грунт',
            'двер',
            'засып',
            'изоляц',
            'кабел',
            'канализац',
            'кладк',
            'котлован',
            'кров',
            'минераловат',
            'окон',
            'окраск',
            'опалуб',
            'освещ',
            'планиров',
            'плитк',
            'проклад',
            'радиатор',
            'разработк',
            'розет',
            'стен',
            'стяжк',
            'тепл',
            'транше',
            'труб',
            'уплотн',
            'утепл',
            'фундамент',
            'шпатлев',
            'штукатур',
        ];
    }

    private function roughRussianStem(string $token): ?string
    {
        if (mb_strlen($token) < 6 || preg_match('/\d/', $token)) {
            return null;
        }

        foreach (['иями', 'ями', 'ами', 'ого', 'его', 'ыми', 'ими', 'иях', 'ах', 'ях', 'ов', 'ев', 'ий', 'ый', 'ой', 'ая', 'ое', 'ые', 'ых', 'их', 'ка', 'ки', 'ку', 'ом', 'ем', 'ам', 'ям', 'ия', 'ие', 'ей', 'ой', 'а', 'ы', 'и', 'е', 'у'] as $suffix) {
            if (str_ends_with($token, $suffix) && mb_strlen($token) - mb_strlen($suffix) >= 4) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $workItem
     * @param array<int, string> $tokens
     */
    private function scorePoolCandidate(
        EstimateNorm $norm,
        array $workItem,
        array $tokens,
        WorkIntentData $intent,
        NormativeSearchProfileData $profile
    ): float
    {
        $score = 0.0;
        $workName = mb_strtolower(trim((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? '')));
        $haystack = mb_strtolower(implode(' ', [
            $norm->code,
            $norm->name,
            $norm->section_name,
            implode(' ', $norm->work_composition ?? []),
        ]));
        $normName = mb_strtolower((string) ($norm->name ?? ''));

        if ($workName !== '' && $workName === $normName) {
            $score += 60.0;
        } elseif ($workName !== '' && (str_contains($normName, $workName) || str_contains($workName, $normName))) {
            $score += 24.0;
        }

        foreach ($tokens as $token) {
            if ($token !== '' && str_contains($haystack, mb_strtolower($token))) {
                $score += 4.0;
            }
        }

        $normUnit = (string) ($norm->unit ?? '');
        $workUnit = (string) ($workItem['unit'] ?? '');
        if ($workUnit !== '' && $normUnit !== '' && NormativeUnitNormalizer::compatible($workUnit, $normUnit)) {
            $score += 50.0;
        } elseif ($workUnit !== '' && $normUnit !== '') {
            $score -= 35.0;
        }

        $sectionCode = (string) ($norm->section_code ?? $norm->section?->code ?? '');
        foreach ($intent->preferredSectionPrefixes as $prefix) {
            if ($sectionCode !== '' && str_starts_with($sectionCode, $prefix)) {
                $score += 20.0;
            }
        }

        foreach ($profile->allowedSectionPrefixes as $prefix) {
            if ($sectionCode !== '' && str_starts_with($sectionCode, $prefix)) {
                $score += 30.0;
                break;
            }
        }

        foreach (array_values(array_unique([...$intent->forbiddenSectionPrefixes, ...$profile->forbiddenSectionPrefixes])) as $prefix) {
            if ($sectionCode !== '' && str_starts_with($sectionCode, $prefix)) {
                $score -= 100.0;
            }
        }

        foreach ($profile->requiredTerms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                $score += 12.0;
            }
        }

        foreach ($profile->synonymTerms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                $score += 5.0;
            }
        }

        $normType = (string) ($norm->collection?->norm_type?->value ?? $norm->collection?->norm_type ?? '');
        if ($normType !== '' && in_array($normType, $intent->preferredNormTypes, true)) {
            $score += 10.0;
        }

        if ($normType !== '' && in_array($normType, $intent->forbiddenNormTypes, true)) {
            $score -= 50.0;
        }

        return $score;
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Collection<int, EstimateNorm> $candidates
     * @return Collection<int, EstimateNorm>
     */
    private function compatibleCandidates(Collection $candidates, string $workUnit): Collection
    {
        if ($workUnit === '') {
            return collect();
        }

        return $candidates
            ->filter(static function (EstimateNorm $norm) use ($workUnit): bool {
                $normUnit = (string) ($norm->unit ?? '');

                return $normUnit !== '' && NormativeUnitNormalizer::compatible($workUnit, $normUnit);
            })
            ->values();
    }
}
