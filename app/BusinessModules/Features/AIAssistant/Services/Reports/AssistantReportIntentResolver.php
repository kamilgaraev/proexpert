<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportDefinition;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;

final readonly class AssistantReportIntentResolver
{
    private const REPORT_MARKERS = [
        'otchet',
        'sformiruy',
        'sdelay',
        'sozday',
        'vygruzi',
        'vygruzka',
        'skachay',
        'podgotov',
    ];

    private const KNOWLEDGE_CONTEXT_MARKERS = [
        'baza znaniy',
        'bazy znaniy',
        'baze znaniy',
        'iz bazy',
        'po dannym',
        'v kontekste',
        'iz konteksta',
        'kontekst',
        'istochnik',
        'istochniki',
        'rag',
    ];

    private const ANALYTICAL_CONTEXT_MARKERS = [
        'analiz',
        'proanaliziruy',
        'sravni',
        'sravnen',
        'sopostav',
        'rashozhden',
        'risk',
        'riski',
        'problemy',
        'nesootvetstv',
        'gde est',
    ];

    private const STOP_WORDS = [
        'dlya',
        'i',
        'ili',
        'mne',
        'na',
        'nado',
        'nuzhen',
        'nuzhno',
        'ob',
        'o',
        'otchet',
        'otcheta',
        'po',
        'pokazhi',
        'podgotov',
        's',
        'skachay',
        'sdelay',
        'sformiruy',
        'so',
        'sozday',
        'vygruzi',
        'za',
    ];

    private AssistantRequestUnderstandingResolver $requestUnderstandingResolver;

    public function __construct(
        private AssistantReportCatalog $catalog = new AssistantReportCatalog,
        ?AssistantRequestUnderstandingResolver $requestUnderstandingResolver = null
    ) {
        $this->requestUnderstandingResolver = $requestUnderstandingResolver ?? new AssistantRequestUnderstandingResolver;
    }

    public function defaultReportDefinition(): ?AssistantReportDefinition
    {
        return $this->catalog->findById('projects_summary')
            ?? $this->catalog->findById('material_movements')
            ?? $this->catalog->all()[0]
            ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     status: 'matched'|'ambiguous'|'missing_type'|'not_report',
     *     definition?: AssistantReportDefinition,
     *     candidates: AssistantReportDefinition[]
     * }
     */
    public function resolve(string $message, array $context = []): array
    {
        $requestUnderstanding = $this->requestUnderstandingResolver->resolve($message, $context);
        if ($this->blocksReportIntent($requestUnderstanding)) {
            return [
                'status' => 'not_report',
                'candidates' => [],
            ];
        }

        $normalized = $this->normalize($message);
        $scores = $this->scoreDefinitions($normalized);
        $contextDefinition = $this->definitionFromContext($context);
        $isReportLike = $this->isReportLike($normalized);
        $hasExplicitReportIntent = $isReportLike || $contextDefinition instanceof AssistantReportDefinition;

        if (! $hasExplicitReportIntent && $this->isAnalyticalContextQuestion($normalized)) {
            return [
                'status' => 'not_report',
                'candidates' => [],
            ];
        }

        if ($this->isKnowledgeContextQuestion($normalized) && ! $isReportLike) {
            return [
                'status' => 'not_report',
                'candidates' => [],
            ];
        }

        if ($scores === [] && $contextDefinition instanceof AssistantReportDefinition && $isReportLike) {
            return [
                'status' => 'matched',
                'definition' => $contextDefinition,
                'candidates' => [$contextDefinition],
            ];
        }

        if ($scores === []) {
            return [
                'status' => $isReportLike ? 'missing_type' : 'not_report',
                'candidates' => $this->catalog->all(),
            ];
        }

        $topScore = max($scores);
        $topDefinitions = [];

        foreach ($this->catalog->all() as $definition) {
            if (($scores[$definition->id] ?? 0) === $topScore) {
                $topDefinitions[] = $definition;
            }
        }

        if (count($topDefinitions) === 1) {
            if ($this->requiresExplicitReportIntent($topDefinitions[0]) && ! $hasExplicitReportIntent) {
                return [
                    'status' => 'not_report',
                    'candidates' => [],
                ];
            }

            return [
                'status' => 'matched',
                'definition' => $topDefinitions[0],
                'candidates' => $topDefinitions,
            ];
        }

        return [
            'status' => 'ambiguous',
            'candidates' => $topDefinitions,
        ];
    }

    private function blocksReportIntent(AssistantRequestUnderstanding $requestUnderstanding): bool
    {
        if ($requestUnderstanding->primaryIntent === 'generate_report') {
            return false;
        }

        foreach (['no_file', 'no_pdf', 'no_report', 'text_only', 'json_only', 'no_actions'] as $constraint) {
            if ($requestUnderstanding->hasConstraint($constraint)) {
                return true;
            }
        }

        return $requestUnderstanding->primaryIntent === 'search_knowledge';
    }

    private function requiresExplicitReportIntent(AssistantReportDefinition $definition): bool
    {
        return $definition->toolName === 'generate_operational_pdf_report';
    }

    /**
     * @return array<string, int>
     */
    private function scoreDefinitions(string $normalized): array
    {
        $scores = [];

        foreach ($this->catalog->all() as $definition) {
            $score = 0;

            foreach ($definition->aliases as $alias) {
                if ($this->contains($normalized, $alias)) {
                    $score += 4;
                }
            }

            foreach ($definition->matchTerms as $term) {
                if ($this->contains($normalized, $term)) {
                    $score += 3;
                }
            }

            $score += $this->semanticScore($normalized, $definition);

            if ($score > 0) {
                $scores[$definition->id] = $score;
            }
        }

        return $scores;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function definitionFromContext(array $context): ?AssistantReportDefinition
    {
        $uiState = $context['ui_state'] ?? [];
        if (! is_array($uiState)) {
            $uiState = [];
        }

        foreach ([
            $context['assistant_report_type'] ?? null,
            $context['report_type'] ?? null,
            $uiState['assistant_report_type'] ?? null,
            $uiState['assistant_report_focus'] ?? null,
        ] as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $definition = $this->catalog->findById($value) ?? $this->catalog->findByToolName($value);
            if ($definition instanceof AssistantReportDefinition) {
                return $definition;
            }
        }

        return null;
    }

    private function isReportLike(string $normalized): bool
    {
        foreach (self::REPORT_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isKnowledgeContextQuestion(string $normalized): bool
    {
        foreach (self::KNOWLEDGE_CONTEXT_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isAnalyticalContextQuestion(string $normalized): bool
    {
        foreach (self::ANALYTICAL_CONTEXT_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function contains(string $normalized, string $needle): bool
    {
        return str_contains($normalized, $this->normalize($needle));
    }

    private function semanticScore(string $normalized, AssistantReportDefinition $definition): int
    {
        $messageStems = $this->significantStems($normalized);
        if ($messageStems === []) {
            return 0;
        }

        $score = 0;

        foreach ($definition->aliases as $alias) {
            if ($this->termMatchesStems($this->normalize($alias), $messageStems)) {
                $score += 2;
            }
        }

        foreach ($definition->matchTerms as $term) {
            if ($this->termMatchesStems($this->normalize($term), $messageStems)) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * @param  string[]  $messageStems
     */
    private function termMatchesStems(string $normalizedTerm, array $messageStems): bool
    {
        $termStems = $this->significantStems($normalizedTerm);
        if ($termStems === []) {
            return false;
        }

        foreach ($termStems as $termStem) {
            $matched = false;

            foreach ($messageStems as $messageStem) {
                if ($this->stemsRelated($termStem, $messageStem)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[]
     */
    private function significantStems(string $normalized): array
    {
        $stems = [];
        foreach (explode(' ', $normalized) as $token) {
            if ($token === '' || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $stem = $this->stemToken($token);
            if (strlen($stem) < 4 || in_array($stem, self::STOP_WORDS, true)) {
                continue;
            }

            $stems[] = $stem;
        }

        return array_values(array_unique($stems));
    }

    private function stemsRelated(string $left, string $right): bool
    {
        return $left === $right
            || str_starts_with($left, $right)
            || str_starts_with($right, $left);
    }

    private function stemToken(string $token): string
    {
        foreach ([
            'iyami',
            'yami',
            'ami',
            'ogo',
            'ego',
            'omu',
            'emu',
            'ymi',
            'imi',
            'aya',
            'uyu',
            'iyu',
            'iye',
            'ye',
            'ie',
            'iy',
            'yy',
            'ym',
            'im',
            'oy',
            'ey',
            'om',
            'em',
            'am',
            'ah',
            'ya',
            'yu',
            'ov',
            'ev',
            'a',
            'u',
            'e',
            'y',
            'i',
        ] as $ending) {
            if (str_ends_with($token, $ending) && strlen($token) - strlen($ending) >= 4) {
                return substr($token, 0, -strlen($ending));
            }
        }

        return $token;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = strtr($value, [
            'а' => 'a',
            'б' => 'b',
            'в' => 'v',
            'г' => 'g',
            'д' => 'd',
            'е' => 'e',
            'ж' => 'zh',
            'з' => 'z',
            'и' => 'i',
            'й' => 'y',
            'к' => 'k',
            'л' => 'l',
            'м' => 'm',
            'н' => 'n',
            'о' => 'o',
            'п' => 'p',
            'р' => 'r',
            'с' => 's',
            'т' => 't',
            'у' => 'u',
            'ф' => 'f',
            'х' => 'h',
            'ц' => 'c',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'sch',
            'ъ' => '',
            'ы' => 'y',
            'ь' => '',
            'э' => 'e',
            'ю' => 'yu',
            'я' => 'ya',
        ]);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }
}
