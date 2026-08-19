<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing;

final readonly class PageAnalysisConsistencyPolicy
{
    public function route(
        PageAnalysisRoutingDecision $decision,
        PageAnalysisServerSignals $signals,
    ): PageAnalysisRoute {
        if ($decision->effectiveRoute !== PageAnalysisRoute::SimpleContext) {
            return $decision->effectiveRoute;
        }

        $consistentSimplePage = in_array($decision->pageKind, ['title', 'divider', 'empty', 'cover'], true)
            && $decision->informationDensity !== 'high'
            && $decision->materialRisk === AnalysisMaterialRisk::Low
            && ! $signals->meaningfulGeometryOrEngineering;

        return $consistentSimplePage
            ? PageAnalysisRoute::SimpleContext
            : PageAnalysisRoute::DenseAmbiguous;
    }
}
