<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\CommentContractProposalRequest;
use App\Http\Requests\Api\V1\Admin\Contract\DecideContractClauseRequest;
use App\Http\Requests\Api\V1\Admin\Contract\RecordExternalContractClauseRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractProposalReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractProposalReviewController extends Controller
{
    public function show(Request $request, int $contract, int $proposal, ContractProposalReviewService $service): JsonResponse
    {
        return AdminResponse::success($service->read($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal));
    }

    public function comment(CommentContractProposalRequest $request, int $contract, int $proposal, ContractProposalReviewService $service): JsonResponse
    {
        return AdminResponse::success($service->comment($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal, $request->validated()));
    }

    public function decide(DecideContractClauseRequest $request, int $contract, int $proposal, ContractProposalReviewService $service): JsonResponse
    {
        return AdminResponse::success($service->decideClause($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal, $request->validated()));
    }

    public function external(RecordExternalContractClauseRequest $request, int $contract, int $proposal, ContractProposalReviewService $service): JsonResponse
    {
        $input = $request->validated();
        $input['asset_id'] = (int) $input['asset_id'];
        return AdminResponse::success($service->decideClause($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal, $input, true));
    }
}
