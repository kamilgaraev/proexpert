<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class EstimateClarificationRouteContractTest extends TestCase
{
    public function test_question_routes_use_the_scoped_thin_admin_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/routes.php');
        $controller = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationQuestionController.php');
        $request = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Http/Requests/AnswerEstimateClarificationRequest.php');
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/{session}/questions'", $routes);
        self::assertStringContainsString("Route::post('/{session}/questions/{question}/answer'", $routes);
        self::assertMatchesRegularExpression("/questions'.*authorize:estimate_generation\.review,project,project/", $routes);
        self::assertMatchesRegularExpression("/questions\/\{question\}\/answer'.*authorize:estimate_generation\.review,project,project/", $routes);
        self::assertIsString($controller);
        self::assertStringContainsString('AdminResponse::success', $controller);
        self::assertStringContainsString('new ActorContext(', $controller);
        self::assertStringNotContainsString('response()->json', $controller);
        self::assertIsString($request);
        self::assertStringContainsString("'answer_fingerprint'", $request);
        self::assertStringContainsString("'expected_source_version'", $request);
        self::assertStringContainsString("'idempotency_key'", $request);
        self::assertIsString($provider);
        foreach ([
            'EstimateClarificationSource::class',
            'EstimateClarificationCatalog::class',
            'EstimateClarificationAnswerRegistry::class',
            'AnswerEstimateClarification::class',
            'ListEstimateClarifications::class',
        ] as $binding) {
            self::assertStringContainsString($binding, $provider);
        }
    }

    public function test_dialogue_history_and_undo_preview_stay_on_the_existing_proposal_boundary(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/routes.php');
        $undo = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Dialogue/PreviewUndoEstimateChangeProposal.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/{session}/assistant/proposals'", $routes);
        self::assertStringContainsString("Route::post('/{session}/assistant/proposals/{proposal}/undo-preview'", $routes);
        self::assertMatchesRegularExpression("/assistant\/proposals'.*authorize:estimate_generation\.view,project,project/", $routes);
        self::assertMatchesRegularExpression("/undo-preview'.*authorize:estimate_generation\.review,project,project/", $routes);
        self::assertIsString($undo);
        self::assertStringContainsString('PreviewEstimateChange $preview', $undo);
        self::assertStringNotContainsString('applyDecision(', $undo);
    }
}
