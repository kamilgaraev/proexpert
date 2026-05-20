<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Reports\AssistantReportDefinition;

final readonly class AssistantReportIntentResolver
{
    private const REPORT_MARKERS = [
        'otchet',
        'sformiruy',
        'sozday',
        'vygruzi',
        'vygruzka',
        'skachay',
        'podgotov',
    ];

    public function __construct(
        private AssistantReportCatalog $catalog = new AssistantReportCatalog
    ) {}

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
        $normalized = $this->normalize($message);
        $scores = $this->scoreDefinitions($normalized);
        $contextDefinition = $this->definitionFromContext($context);

        if ($scores === [] && $contextDefinition instanceof AssistantReportDefinition && $this->isReportLike($normalized)) {
            return [
                'status' => 'matched',
                'definition' => $contextDefinition,
                'candidates' => [$contextDefinition],
            ];
        }

        if ($scores === []) {
            return [
                'status' => $this->isReportLike($normalized) ? 'missing_type' : 'not_report',
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

    private function contains(string $normalized, string $needle): bool
    {
        return str_contains($normalized, $this->normalize($needle));
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
