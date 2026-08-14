<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;

final readonly class PageAnalysisPlan
{
    /** @param list<ObserverProfile> $observers @param list<string> $routingReasons */
    private function __construct(
        public PageAnalysisRoute $route,
        public array $observers,
        public bool $requiresArbitration,
        public bool $usesSemanticRegions,
        public array $routingReasons,
        public bool $reusesLiteralObserver = false,
    ) {}

    public static function fromDecision(
        PageAnalysisRoutingDecision $decision,
        bool $semanticDisagreement = false,
    ): self {
        $route = $decision->effectiveRoute;
        $observers = match ($route) {
            PageAnalysisRoute::SimpleContext => [ObserverProfile::Literal],
            PageAnalysisRoute::StructuredTextual => [ObserverProfile::Literal, ObserverProfile::Construction],
            PageAnalysisRoute::DenseAmbiguous => ObserverProfile::cases(),
        };
        $arbitration = $route === PageAnalysisRoute::DenseAmbiguous
            || ($route === PageAnalysisRoute::StructuredTextual
                && ($decision->materialRisk || $semanticDisagreement));
        $reasons = $decision->reasons;
        if ($decision->effectiveRoute !== $decision->requestedRoute) {
            $reasons[] = 'server_minimum_analysis_depth';
        }
        if ($decision->failOpenEscalated) {
            $reasons[] = 'fail_open_unknown_or_low_confidence';
        }

        return new self(
            $route,
            $observers,
            $arbitration,
            $route === PageAnalysisRoute::DenseAmbiguous && $decision->semanticRegions !== [],
            array_values(array_unique($reasons)),
        );
    }

    public function escalateForCrossDocumentReference(): self
    {
        return new self(
            PageAnalysisRoute::DenseAmbiguous,
            ObserverProfile::cases(),
            true,
            true,
            [...$this->routingReasons, 'cross_document_reference'],
            true,
        );
    }

    public function providerCallCount(): int
    {
        return count($this->observers) + ($this->requiresArbitration ? 1 : 0);
    }

    public function additionalProviderCallCount(): int
    {
        return $this->providerCallCount() - ($this->reusesLiteralObserver ? 1 : 0);
    }

    public function successfulOutcome(): string
    {
        return $this->route === PageAnalysisRoute::SimpleContext
            ? 'ready_context'
            : 'ready_calculation';
    }
}
