<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditOperations;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EstimateGenerationCreditController extends Controller
{
    public function show(Request $request, Project $project, EstimateGenerationSession $session, string $operation): JsonResponse
    {
        $scoped = EstimateGenerationSession::query()->where('organization_id', $request->user()->current_organization_id)
            ->where('project_id', $project->id)->whereKey($session->id)->firstOrFail();
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class)
            ->authorize($request->user(), $scoped, 'estimate_generation.view');
        $row = \Illuminate\Support\Facades\DB::table('estimate_generation_credit_operations')->where('operation_id', $operation)
            ->where('organization_id', $scoped->organization_id)->where('project_id', $scoped->project_id)->where('session_id', $scoped->id)->first();
        abort_if($row === null, 404);

        return AdminResponse::success(['operation_id' => $row->operation_id, 'operation_kind' => $row->kind, 'status' => $row->status,
            'state_version' => (int) $scoped->state_version, 'receipt' => $row->receipt === null ? null : json_decode($row->receipt, true, 64, JSON_THROW_ON_ERROR)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function quote(Request $request, Project $project, EstimateGenerationSession $session, EstimatorCreditOperations $operations): JsonResponse
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'state_version' => ['required', 'integer', 'min:0'],
            'operation_kind' => ['required', 'in:generation,analysis']]);
        if (! \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization((int) $request->user()->current_organization_id)) {
            return AdminResponse::error('Общий кошелёк сметчика ещё не включён.', 409);
        }
        try {
            return AdminResponse::success($operations->quote($request->user(), (int) $project->id, (int) $session->id,
                $input['state_version'], $input['request_id'], $input['operation_kind']))->header('Cache-Control', 'private, no-store');
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['quote' => match ($exception->getMessage()) {
                'estimator_product_not_available' => trans_message('estimate_evaluation.product_not_available'),
                'estimator_credit_document_preparation_required' => 'Сначала дождитесь подготовки страниц документа. Она не расходует единицы ИИ.',
                'estimator_credit_quote_expired' => 'Предложение стоимости истекло. Получите новое перед запуском.',
                'estimator_credit_request_id_conflict' => 'Этот запрос относится к другой версии исходных данных. Получите новое предложение.',
                default => 'Не удалось рассчитать стоимость операции для текущих данных.',
            }]);
        }
    }
}
