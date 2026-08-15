<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisPlan;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoute;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoutingDecision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdaptivePageRoutingTest extends TestCase
{
    #[Test]
    public function server_page_kind_signal_can_only_increase_requested_depth(): void
    {
        $decision = PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => 'specification',
            'requested_depth' => 'simple_context',
            'information_density' => 'medium',
            'readability' => 'high',
            'confidence' => 0.95,
            'ambiguous' => false,
            'material_risk' => 'low',
            'reasons' => ['Классифицирована ведомость.'],
            'semantic_regions' => [],
        ]);

        self::assertSame(PageAnalysisRoute::StructuredTextual, $decision->effectiveRoute);
        self::assertSame(4, PageAnalysisPlan::fromDecision($decision)->providerCallCount());
    }

    #[Test]
    public function title_page_is_ready_context_after_exactly_one_literal_observer_call(): void
    {
        $decision = PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => 'title',
            'requested_depth' => 'simple_context',
            'information_density' => 'low',
            'readability' => 'high',
            'confidence' => 0.98,
            'ambiguous' => false,
            'material_risk' => 'low',
            'reasons' => ['Титульный лист однозначно задаёт раздел АР.'],
            'semantic_regions' => [],
        ]);

        $plan = PageAnalysisPlan::fromDecision($decision);

        self::assertSame(PageAnalysisRoute::SimpleContext, $plan->route);
        self::assertSame([ObserverProfile::Literal], $plan->observers);
        self::assertFalse($plan->requiresArbitration);
        self::assertSame(1, $plan->providerCallCount());
        self::assertSame('ready_context', $plan->successfulOutcome());
    }

    #[Test]
    public function specification_uses_three_independent_observers_and_always_arbitrates(): void
    {
        $decision = $this->decision('specification', 'structured_textual');

        $ordinary = PageAnalysisPlan::fromDecision($decision);
        $disagreement = PageAnalysisPlan::fromDecision($decision, semanticDisagreement: true);

        self::assertSame(ObserverProfile::cases(), $ordinary->observers);
        self::assertTrue($ordinary->requiresArbitration);
        self::assertSame(4, $ordinary->providerCallCount());
        self::assertTrue($disagreement->requiresArbitration);
        self::assertSame(4, $disagreement->providerCallCount());
    }

    #[Test]
    public function dense_drawing_uses_three_independent_observers_arbiter_and_regions(): void
    {
        $decision = PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => 'drawing',
            'requested_depth' => 'dense_ambiguous',
            'information_density' => 'high',
            'readability' => 'medium',
            'confidence' => 0.94,
            'ambiguous' => true,
            'material_risk' => 'high',
            'reasons' => ['На листе совмещены план, разрез и узлы с мелкими размерами.'],
            'semantic_regions' => [[
                'label' => 'Размерная цепочка фасада',
                'purpose' => 'microtext',
                'box' => [0.08, 0.12, 0.46, 0.34],
            ]],
        ]);

        $plan = PageAnalysisPlan::fromDecision($decision);

        self::assertSame(PageAnalysisRoute::DenseAmbiguous, $plan->route);
        self::assertSame(ObserverProfile::cases(), $plan->observers);
        self::assertTrue($plan->requiresArbitration);
        self::assertTrue($plan->usesSemanticRegions);
        self::assertSame(4, $plan->providerCallCount());
    }

    #[Test]
    public function unknown_or_low_confidence_literal_result_escalates_instead_of_discarding_page(): void
    {
        $decision = PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => 'unknown',
            'requested_depth' => 'simple_context',
            'information_density' => 'low',
            'readability' => 'low',
            'confidence' => 0.41,
            'ambiguous' => false,
            'material_risk' => 'low',
            'reasons' => ['Тип листа не определён.'],
            'semantic_regions' => [],
        ]);

        $plan = PageAnalysisPlan::fromDecision($decision);

        self::assertSame(PageAnalysisRoute::DenseAmbiguous, $plan->route);
        self::assertSame(4, $plan->providerCallCount());
        self::assertContains('fail_open_unknown_or_low_confidence', $plan->routingReasons);
    }

    #[Test]
    public function cross_document_escalation_reuses_literal_result_and_only_adds_missing_calls(): void
    {
        $initial = PageAnalysisPlan::fromDecision($this->decision('title', 'simple_context'));
        $escalated = $initial->escalateForCrossDocumentReference();

        self::assertTrue($escalated->reusesLiteralObserver);
        self::assertSame(3, $escalated->additionalProviderCallCount());
        self::assertSame(4, $escalated->providerCallCount());
    }

    #[Test]
    public function mixed_two_hundred_page_suite_stays_well_below_unconditional_eight_hundred_calls(): void
    {
        $plans = [
            ...array_fill(0, 80, PageAnalysisPlan::fromDecision($this->decision('title', 'simple_context'))),
            ...array_fill(0, 80, PageAnalysisPlan::fromDecision($this->decision('specification', 'structured_textual'))),
            ...array_fill(0, 20, PageAnalysisPlan::fromDecision($this->decision('specification', 'structured_textual', true))),
            ...array_fill(0, 20, PageAnalysisPlan::fromDecision($this->decision('drawing', 'dense_ambiguous', true))),
        ];

        $calls = array_sum(array_map(static fn (PageAnalysisPlan $plan): int => $plan->providerCallCount(), $plans));

        self::assertSame(200, count($plans));
        self::assertSame(560, $calls);
        self::assertLessThan(600, $calls);
    }

    private function decision(string $kind, string $depth, bool $risk = false): PageAnalysisRoutingDecision
    {
        return PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => $kind,
            'requested_depth' => $depth,
            'information_density' => $depth === 'dense_ambiguous' ? 'high' : 'medium',
            'readability' => 'high',
            'confidence' => 0.96,
            'ambiguous' => $depth === 'dense_ambiguous',
            'material_risk' => $risk ? 'high' : 'low',
            'reasons' => ['Маршрут определён по содержимому листа.'],
            'semantic_regions' => $depth === 'dense_ambiguous' ? [[
                'label' => 'Главный чертёж',
                'purpose' => 'drawing',
                'box' => [0.1, 0.1, 0.9, 0.9],
            ]] : [],
        ]);
    }
}
