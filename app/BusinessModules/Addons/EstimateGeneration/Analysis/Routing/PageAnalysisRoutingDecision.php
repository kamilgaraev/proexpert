<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

use InvalidArgumentException;

final readonly class PageAnalysisRoutingDecision
{
    private const PAGE_KINDS = [
        'title', 'divider', 'empty', 'cover', 'narrative', 'specification', 'schedule',
        'explication', 'legend', 'index', 'drawing', 'combined', 'unknown',
    ];

    private const DENSITIES = ['low', 'medium', 'high'];

    private const READABILITY = ['high', 'medium', 'low'];

    /**
     * @param  list<string>  $reasons
     * @param  list<array<string, mixed>>  $semanticRegions
     */
    private function __construct(
        public string $pageKind,
        public PageAnalysisRoute $requestedRoute,
        public PageAnalysisRoute $effectiveRoute,
        public string $informationDensity,
        public string $readability,
        public float $confidence,
        public bool $ambiguous,
        public bool $materialRisk,
        public array $reasons,
        public array $semanticRegions,
        public bool $failOpenEscalated,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromProviderArray(array $payload): self
    {
        $expected = [
            'page_kind', 'requested_depth', 'information_density', 'readability', 'confidence',
            'ambiguous', 'material_risk', 'reasons', 'semantic_regions',
        ];
        if (count($payload) !== count($expected) || array_diff(array_keys($payload), $expected) !== []) {
            throw new InvalidArgumentException('page_analysis_routing_schema_invalid');
        }
        $route = is_string($payload['requested_depth'] ?? null)
            ? PageAnalysisRoute::tryFrom($payload['requested_depth'])
            : null;
        $confidence = $payload['confidence'] ?? null;
        if (! is_string($payload['page_kind'] ?? null)
            || ! in_array($payload['page_kind'], self::PAGE_KINDS, true)
            || ! $route instanceof PageAnalysisRoute
            || ! is_string($payload['information_density'] ?? null)
            || ! in_array($payload['information_density'], self::DENSITIES, true)
            || ! is_string($payload['readability'] ?? null)
            || ! in_array($payload['readability'], self::READABILITY, true)
            || (! is_float($confidence) && ! is_int($confidence))
            || ! is_finite((float) $confidence)
            || (float) $confidence < 0.0 || (float) $confidence > 1.0
            || ! is_bool($payload['ambiguous'] ?? null)
            || ! is_bool($payload['material_risk'] ?? null)
            || ! is_array($payload['reasons'] ?? null) || ! array_is_list($payload['reasons'])
            || count($payload['reasons']) < 1 || count($payload['reasons']) > 8
            || ! is_array($payload['semantic_regions'] ?? null) || ! array_is_list($payload['semantic_regions'])
            || count($payload['semantic_regions']) > 16) {
            throw new InvalidArgumentException('page_analysis_routing_schema_invalid');
        }
        $reasons = [];
        foreach ($payload['reasons'] as $reason) {
            if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('page_analysis_routing_reason_invalid');
            }
            $reasons[] = trim($reason);
        }
        foreach ($payload['semantic_regions'] as $region) {
            if (! is_array($region)) {
                throw new InvalidArgumentException('page_analysis_routing_region_invalid');
            }
        }

        $failOpen = $payload['page_kind'] === 'unknown'
            || $payload['readability'] === 'low'
            || (float) $confidence < 0.8
            || $payload['ambiguous'] === true;
        $minimumRoute = match ($payload['page_kind']) {
            'title', 'divider', 'empty', 'cover' => PageAnalysisRoute::SimpleContext,
            'narrative', 'specification', 'schedule', 'explication', 'legend', 'index' => PageAnalysisRoute::StructuredTextual,
            default => PageAnalysisRoute::DenseAmbiguous,
        };
        $effective = $failOpen
            ? PageAnalysisRoute::DenseAmbiguous
            : self::deeperRoute($route, $minimumRoute);

        return new self(
            $payload['page_kind'],
            $route,
            $effective,
            $payload['information_density'],
            $payload['readability'],
            (float) $confidence,
            $payload['ambiguous'],
            $payload['material_risk'],
            $reasons,
            array_values($payload['semantic_regions']),
            $failOpen,
        );
    }

    public static function failOpen(string $reason): self
    {
        return new self(
            'unknown',
            PageAnalysisRoute::DenseAmbiguous,
            PageAnalysisRoute::DenseAmbiguous,
            'high',
            'low',
            0.0,
            true,
            true,
            [$reason],
            [],
            true,
        );
    }

    private static function deeperRoute(PageAnalysisRoute $requested, PageAnalysisRoute $minimum): PageAnalysisRoute
    {
        $depth = [
            PageAnalysisRoute::SimpleContext->value => 1,
            PageAnalysisRoute::StructuredTextual->value => 2,
            PageAnalysisRoute::DenseAmbiguous->value => 3,
        ];

        return $depth[$requested->value] >= $depth[$minimum->value] ? $requested : $minimum;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'page_kind' => $this->pageKind,
            'requested_depth' => $this->requestedRoute->value,
            'information_density' => $this->informationDensity,
            'readability' => $this->readability,
            'confidence' => $this->confidence,
            'ambiguous' => $this->ambiguous,
            'material_risk' => $this->materialRisk,
            'reasons' => $this->reasons,
            'semantic_regions' => $this->semanticRegions,
        ];
    }
}
