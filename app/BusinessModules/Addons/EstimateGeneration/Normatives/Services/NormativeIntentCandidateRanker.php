<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final readonly class NormativeIntentCandidateRanker
{
    public function __construct(
        private NormativeSemanticCompatibilityService $semanticCompatibility = new NormativeSemanticCompatibilityService,
    ) {}

    /** @param list<object> $candidates @param non-empty-list<array{search_text: string, unit: string, code?: string|null, material?: string|null, action?: string|null, scope?: string|null, system?: string|null, object?: string|null, object_type?: string|null, normative_section?: string|null, normative_sections?: list<string>}> $intents @return list<object>|null */
    public function select(array $candidates, array $intents): ?array
    {
        $selected = [];
        foreach ($intents as $intent) {
            $ranked = [];
            foreach ($candidates as $candidate) {
                $score = $this->score($candidate, $intent);
                if ($score !== null) {
                    $ranked[] = [$score, (int) $candidate->id, $candidate];
                }
            }
            usort($ranked, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
            if ($ranked === []) {
                continue;
            }
            foreach (array_slice($ranked, 0, 2) as $row) {
                $selected[(int) $row[2]->id] = $row[2];
            }
        }
        if ($selected === [] || count($selected) > 128) {
            return null;
        }

        return array_values($selected);
    }

    /** @param array{search_text: string, unit: string, code?: string|null, material?: string|null, action?: string|null, scope?: string|null, system?: string|null, object?: string|null, object_type?: string|null, normative_section?: string|null, normative_sections?: list<string>} $intent */
    private function score(object $candidate, array $intent): ?int
    {
        $unit = (string) ($candidate->canonical_unit ?: $candidate->unit);
        if (! NormativeUnitNormalizer::compatible($unit, $intent['unit'])) {
            return null;
        }
        $preferredSections = array_values(array_filter(
            $intent['normative_sections'] ?? [],
            static fn (mixed $section): bool => is_string($section) && $section !== '',
        ));
        $preferredSection = trim((string) ($intent['normative_section'] ?? ''));
        if ($preferredSections === [] && $preferredSection !== '') {
            $preferredSections = [$preferredSection];
        }
        $candidateSection = trim((string) ($candidate->section_code ?? ''));
        $sectionMatches = false;
        foreach ($preferredSections as $section) {
            if (str_starts_with($candidateSection, $section)) {
                $sectionMatches = true;
                break;
            }
        }
        if ($preferredSections !== [] && ! $sectionMatches) {
            return null;
        }
        $name = mb_strtolower((string) $candidate->name);
        $code = mb_strtolower((string) $candidate->code);
        $search = mb_strtolower($intent['search_text']);
        $requestedCode = mb_strtolower((string) ($intent['code'] ?? ''));
        if ($requestedCode !== '' && $code !== $requestedCode) {
            return null;
        }
        $semanticText = implode(' ', [
            (string) ($candidate->name ?? ''),
            is_array($candidate->work_composition ?? null)
                ? implode(' ', array_filter($candidate->work_composition, 'is_string'))
                : (string) ($candidate->work_composition ?? ''),
        ]);
        if (! $this->semanticCompatibility->isCompatible(
            $semanticText,
            $intent['search_text'],
            [...$intent, 'candidate_title' => (string) ($candidate->name ?? '')],
        )) {
            return null;
        }
        if ($requestedCode !== '' && $code === $requestedCode) {
            return 0;
        }
        if ($name === $search) {
            return 1;
        }
        $haystack = mb_strtolower(implode(' ', [
            $name,
            (string) ($candidate->section_name ?? ''),
            is_array($candidate->work_composition ?? null)
                ? implode(' ', array_filter($candidate->work_composition, 'is_string'))
                : (string) ($candidate->work_composition ?? ''),
        ]));
        $tokens = $this->tokens($search);
        $matches = count(array_filter($tokens, static fn (string $token): bool => str_contains($haystack, $token)));

        return $matches > 0
            ? 100 - min(99, $matches) + $this->objectContextPriority($name, $search, $intent)
            : null;
    }

    /** @param array<string, mixed> $intent */
    private function objectContextPriority(string $candidateName, string $search, array $intent): int
    {
        if (! ObjectTypeSignalClassifier::isResidential((string) ($intent['object_type'] ?? ''))
            || ($intent['action'] ?? null) !== 'pipe_layout') {
            return 0;
        }
        if ($this->containsAny($search, ['наружн', 'транше', 'выпуск'])) {
            return 0;
        }
        if (str_contains($candidateName, 'внутренн')) {
            return -10;
        }

        return $this->containsAny($candidateName, ['наружн', 'транше']) ? 10 : 0;
    }

    /** @param list<string> $markers */
    private function containsAny(string $text, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function tokens(string $search): array
    {
        $tokens = [];
        foreach (preg_split('/[^\pL\pN.-]+/u', mb_strtolower($search)) ?: [] as $token) {
            if (mb_strlen($token) < 3 || NormativeLexemePolicy::isGeneric($token)) {
                continue;
            }
            $tokens[$token] = true;
            foreach (['иями', 'ями', 'ами', 'ого', 'его', 'ыми', 'ими', 'иях', 'ах', 'ях', 'ов', 'ев', 'ий', 'ый', 'ой', 'ая', 'ое', 'ые', 'ых', 'их', 'ка', 'ки', 'ку', 'ом', 'ем', 'ам', 'ям', 'ия', 'ие', 'ей', 'а', 'ы', 'и', 'е', 'у'] as $suffix) {
                if (str_ends_with($token, $suffix) && mb_strlen($token) - mb_strlen($suffix) >= 4) {
                    $stem = mb_substr($token, 0, mb_strlen($token) - mb_strlen($suffix));
                    $tokens[$stem] = true;
                    if (str_ends_with($stem, 'н') && mb_strlen($stem) >= 5) {
                        $tokens[mb_substr($stem, 0, -1)] = true;
                    }
                    break;
                }
            }
        }

        return array_keys($tokens);
    }
}
