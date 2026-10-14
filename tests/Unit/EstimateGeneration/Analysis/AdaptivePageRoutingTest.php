<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisPlan;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoute;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisRoutingDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Routing\PageAnalysisServerSignals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdaptivePageRoutingTest extends TestCase
{
    #[Test]
    public function explicit_low_risk_photo_context_needs_only_the_first_observer(): void
    {
        $decision = $this->decision('photo_scene', 'simple_context');
        $signals = PageAnalysisServerSignals::fromLiteralObservation([
            'sheet_type' => 'photo', 'elements' => [], 'raw_facts' => [], 'warnings' => ['scale_missing'],
        ]);
        $plan = PageAnalysisPlan::fromDecision($decision, serverSignals: $signals);

        self::assertSame(PageAnalysisRoute::SimpleContext, $plan->route);
        self::assertSame([ObserverProfile::Literal], $plan->observers);
        self::assertSame(1, $plan->providerCallCount());
        self::assertFalse($plan->requiresArbitration);
    }

    #[Test]
    #[DataProvider('photoEscalationCases')]
    public function photo_context_cannot_bypass_consistency_or_unknown_data(array $routing, array $observation): void
    {
        $payload = array_replace($this->decision('photo_scene', 'simple_context')->toArray(), $routing);
        // toArray also exposes server-derived fields; provider contract has only these nine keys.
        $payload = array_intersect_key($payload, array_flip(['page_kind', 'requested_depth', 'information_density',
            'readability', 'confidence', 'ambiguous', 'material_risk', 'reasons', 'semantic_regions']));
        $decision = PageAnalysisRoutingDecision::fromProviderArray($payload);
        $signals = PageAnalysisServerSignals::fromLiteralObservation(array_replace([
            'sheet_type' => 'photo', 'elements' => [], 'raw_facts' => [], 'warnings' => ['scale_missing'],
        ], $observation));
        self::assertSame(3, PageAnalysisPlan::fromDecision($decision, serverSignals: $signals)->providerCallCount());
    }

    public static function photoEscalationCases(): iterable
    {
        yield 'missing server photo signal' => [[], ['sheet_type' => 'unknown']];
        yield 'geometry' => [[], ['elements' => [['type' => 'dimension']]]];
        yield 'engineering' => [[], ['raw_facts' => [['factType' => 'equipment']]]];
        yield 'generic proposed fact' => [[], ['raw_facts' => [['factType' => 'risk']]]];
        yield 'secondary facts cannot hide behind empty raw list' => [[], ['project_sheet_analysis' => ['facts' => [['factType' => 'material']]]]];
        yield 'known roof form' => [[], ['visual_attributes' => ['roof_type' => ['value' => 'pitched']]]];
        yield 'scale candidate' => [[], ['scale_candidates' => [['source' => 'known_object']]]];
        yield 'low confidence warning' => [[], ['warnings' => ['low_confidence']]];
        yield 'missing warnings' => [[], ['warnings' => null]];
        yield 'density' => [['information_density' => 'high'], []];
        yield 'material risk' => [['material_risk' => 'medium'], []];
        yield 'uncertainty' => [['ambiguous' => true], []];
        yield 'confidence threshold' => [['confidence' => 0.94], []];
        yield 'readability' => [['readability' => 'medium'], []];
        yield 'unknown kind' => [['page_kind' => 'unknown'], []];
        yield 'regions' => [['semantic_regions' => [['label' => 'Detail', 'purpose' => 'drawing', 'box' => [0, 0, 1, 1]]]], []];
        yield 'model asks for more' => [['requested_depth' => 'dense_ambiguous'], []];
    }

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
        self::assertSame(3, PageAnalysisPlan::fromDecision($decision)->providerCallCount());
        self::assertSame(4, PageAnalysisPlan::fromDecision($decision)->maximumProviderCallCount());
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
    public function contradictory_high_risk_dense_title_cannot_choose_the_one_observer_route(): void
    {
        $decision = PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => 'title',
            'requested_depth' => 'simple_context',
            'information_density' => 'high',
            'readability' => 'high',
            'confidence' => 0.99,
            'ambiguous' => false,
            'material_risk' => 'high',
            'reasons' => ['Observer назвал сложный лист титульным.'],
            'semantic_regions' => [],
        ]);

        $plan = PageAnalysisPlan::fromDecision($decision);

        self::assertSame(PageAnalysisRoute::DenseAmbiguous, $plan->route);
        self::assertSame(ObserverProfile::cases(), $plan->observers);
        self::assertFalse($plan->requiresArbitration);
        self::assertSame(3, $plan->providerCallCount());
        self::assertContains('server_consistency_hard_floor', $plan->routingReasons);
    }

    #[Test]
    public function meaningful_geometry_or_engineering_content_cannot_remain_simple_context(): void
    {
        $decision = $this->decision('title', 'simple_context');
        $signals = PageAnalysisServerSignals::fromLiteralObservation([
            'elements' => [
                ['type' => 'dimension'],
                ['type' => 'engineering_element'],
            ],
            'project_sheet_analysis' => [
                'role' => 'plan',
                'facts' => [['factType' => 'dimension_chain']],
            ],
        ]);

        $plan = PageAnalysisPlan::fromDecision($decision, serverSignals: $signals);

        self::assertSame(PageAnalysisRoute::DenseAmbiguous, $plan->route);
        self::assertSame(3, $plan->providerCallCount());
        self::assertContains('server_meaningful_geometry_or_engineering', $plan->routingReasons);
    }

    #[Test]
    public function specification_uses_specialized_observers_and_arbitrates_only_when_their_claims_conflict(): void
    {
        $decision = $this->decision('specification', 'structured_textual');

        $ordinary = PageAnalysisPlan::fromDecision($decision);
        $disagreement = PageAnalysisPlan::fromDecision($decision, semanticDisagreement: true);

        self::assertSame(ObserverProfile::cases(), $ordinary->observers);
        self::assertFalse($ordinary->requiresArbitration);
        self::assertSame(3, $ordinary->providerCallCount());
        self::assertTrue($disagreement->requiresArbitration);
        self::assertSame(4, $disagreement->providerCallCount());
    }

    #[Test]
    public function dense_drawing_preserves_regions_and_reserves_a_possible_arbiter_without_always_calling_it(): void
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
        self::assertFalse($plan->requiresArbitration);
        self::assertTrue($plan->usesSemanticRegions);
        self::assertSame(3, $plan->providerCallCount());
        self::assertSame(4, $plan->withObservedDisagreement(true)->providerCallCount());
        self::assertFalse($plan->withObservedDisagreement(false)->requiresArbitration);
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
        self::assertSame(3, $plan->providerCallCount());
        self::assertContains('fail_open_unknown_or_low_confidence', $plan->routingReasons);
    }

    #[Test]
    public function cross_document_escalation_reuses_literal_result_and_only_adds_missing_calls(): void
    {
        $initial = PageAnalysisPlan::fromDecision($this->decision('title', 'simple_context'));
        $escalated = $initial->escalateForCrossDocumentReference();

        self::assertTrue($escalated->reusesLiteralObserver);
        self::assertSame(2, $escalated->additionalProviderCallCount());
        self::assertSame(3, $escalated->providerCallCount());
        self::assertSame(3, $escalated->withObservedDisagreement(true)->additionalProviderCallCount());
        self::assertTrue($escalated->withObservedDisagreement(true)->reusesLiteralObserver);
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
        self::assertSame(440, $calls);
        self::assertLessThan(600, $calls);
    }

    private function decision(string $kind, string $depth, bool $risk = false): PageAnalysisRoutingDecision
    {
        return PageAnalysisRoutingDecision::fromProviderArray([
            'page_kind' => $kind,
            'requested_depth' => $depth,
            'information_density' => match ($depth) {
                'simple_context' => 'low',
                'dense_ambiguous' => 'high',
                default => 'medium',
            },
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
