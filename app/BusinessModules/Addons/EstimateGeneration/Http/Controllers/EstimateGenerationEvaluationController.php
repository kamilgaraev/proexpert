<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetCurrentEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationPriceSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RecalculateUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationPriceSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationRequirementDecision;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScopeSelection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveEvaluationRequirementDecisionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationEvaluationController extends Controller
{
    public function confirmScenario(Request $request, Project $project, EstimateGenerationSession $session,
        \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmEvaluationScenario $confirmation): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'revision_id' => ['required', 'uuid'], 'content_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'scenario_id' => ['required', 'in:minimum,base,maximum'], 'assumptions_accepted' => ['required', 'boolean']]);

        return AdminResponse::success($confirmation->handle($request->user(), (int) $project->id, (int) $session->id, $input))
            ->header('Cache-Control', 'private, no-store');
    }

    public function scenarios(Request $request, Project $project, EstimateGenerationSession $session,
        \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationScenarioSet $scenarios): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'definitions' => ['required', 'array', 'size:3'], 'definitions.*.id' => ['required', 'distinct', 'in:minimum,base,maximum'],
            'definitions.*.basis' => ['required', 'string', 'max:1000'], 'definitions.*.quantities' => ['required', 'array', 'min:1', 'max:10000'],
            'definitions.*.quantities.*.work_item_key' => ['required', 'string', 'max:160'],
            'definitions.*.quantities.*.value' => ['required', 'string', 'max:17'],
            'definitions.*.quantities.*.unit' => ['required', 'string', 'max:30']]);

        return AdminResponse::success($scenarios->handle($request->user(), (int) $project->id, (int) $session->id, $input))
            ->header('Cache-Control', 'private, no-store');
    }

    public function pricingSource(Request $request, Project $project, EstimateGenerationSession $session,
        \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveSessionPricingSource $prices): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'estimate_regional_price_version_id' => ['required', 'integer', 'min:1']]);

        return AdminResponse::success($prices->handle($request->user(), (int) $project->id, (int) $session->id, $input))->header('Cache-Control', 'private, no-store');
    }

    public function quantitySources(Request $request, Project $project, EstimateGenerationSession $session,
        \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationQuantitySources $sources): JsonResponse
    {
        $input = $request->validate(['work_item_key' => ['required', 'string', 'max:160'], 'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return AdminResponse::success($sources->handle($request->user(), (int) $project->id, (int) $session->id, $input['work_item_key'],
            $input['search'] ?? '', (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25)))->header('Cache-Control', 'private, no-store');
    }

    public function quantity(Request $request, Project $project, EstimateGenerationSession $session, SaveEvaluationWorkQuantity $quantities): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'work_item_key' => ['required', 'string', 'max:160'], 'reason' => ['required', 'string', 'max:1000'],
            'value' => ['required_without:source_fact_id', 'prohibited_with:source_fact_id', 'string', 'max:21'],
            'unit' => ['required_without:source_fact_id', 'prohibited_with:source_fact_id', 'string', 'max:30'],
            'source_fact_id' => ['required_without:value', 'string', 'max:191'],
            'source_fact_version' => ['required_with:source_fact_id', 'integer', 'min:1'],
            'source_value_hash' => ['required_with:source_fact_id', 'string', 'regex:/\A[a-f0-9]{64}\z/']]);

        return AdminResponse::success($quantities->handle($request->user(), (int) $project->id, (int) $session->id, $input))->header('Cache-Control', 'private, no-store');
    }

    public function scope(Request $request, Project $project, EstimateGenerationSession $session, SaveEvaluationScopeSelection $scopes): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'selected_sections' => ['required', 'array', 'min:1', 'max:12'], 'selected_sections.*' => ['required', 'string', 'distinct', 'max:120'],
            'profile_id' => ['required', 'in:construction,renovation,industrial'],
            'construction_type' => ['required', 'in:new_construction,current_repair,capital_repair,reconstruction'],
            'price_policy' => ['required', 'in:normative,catalog,mixed']]);

        return AdminResponse::success($scopes->handle($request->user(), (int) $project->id, (int) $session->id, $input))->header('Cache-Control', 'private, no-store');
    }

    public function sources(Request $request, Project $project, EstimateGenerationSession $session, GetEvaluationPriceSources $sources): JsonResponse
    {
        $input = $request->validate(['work_item_key' => ['required', 'string', 'max:160'], 'source_type' => ['sometimes', 'in:catalog,work_type'],
            'search' => ['sometimes', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);

        return AdminResponse::success($sources->handle($request->user(), (int) $project->id, (int) $session->id, $input['work_item_key'],
            $input['source_type'] ?? 'catalog', $input['search'] ?? '', (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25)))->header('Cache-Control', 'private, no-store');
    }

    public function price(Request $request, Project $project, EstimateGenerationSession $session, SaveEvaluationPriceSelection $prices): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'work_item_key' => ['required', 'string', 'max:160'], 'source_reference' => ['required', 'string', 'max:100', 'regex:/\A(?:(?:catalog|work_type):[1-9][0-9]*|supplier:[1-9][0-9]*:[1-9][0-9]*)\z/'],
            'source_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'stale_accepted' => ['sometimes', 'boolean']]);

        return AdminResponse::success($prices->handle($request->user(), (int) $project->id, (int) $session->id, $input))->header('Cache-Control', 'private, no-store');
    }

    public function recalculate(Request $request, Project $project, EstimateGenerationSession $session, RecalculateUniversalEvaluation $recalculation): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0']]);

        return AdminResponse::success($recalculation->handle($request->user(), (int) $project->id, (int) $session->id,
            (int) $input['state_version'], $input['request_id']))->header('Cache-Control', 'private, no-store');
    }

    public function requirement(SaveEvaluationRequirementDecisionRequest $request, Project $project, EstimateGenerationSession $session,
        SaveEvaluationRequirementDecision $decisions): JsonResponse
    {
        return AdminResponse::success($decisions->handle($request->user(), (int) $project->id, (int) $session->id, $request->validated()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Project $project, EstimateGenerationSession $session, GetCurrentEvaluation $evaluation): JsonResponse
    {
        return AdminResponse::success($evaluation->handle($request->user(), (int) $project->id, (int) $session->id))
            ->header('Cache-Control', 'private, no-store');
    }
}
