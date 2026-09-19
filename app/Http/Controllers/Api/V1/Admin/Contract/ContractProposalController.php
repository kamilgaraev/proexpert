<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\CreateContractProposalRequest;
use App\Http\Requests\Api\V1\Admin\Contract\DecideContractProposalRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ListContractProposalsRequest;
use App\Http\Requests\Api\V1\Admin\Contract\RecordExternalContractDecisionRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractProposalResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractProposalController extends Controller
{
    public function index(ListContractProposalsRequest $request, int $contract, ContractProposalService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->listing($request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            isset($data['before']) ? (int) $data['before'] : null));
    }

    public function store(CreateContractProposalRequest $request, int $contract, ContractProposalService $service): JsonResponse
    {
        $data = $request->validated();
        $result = $service->create($request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            (int) $data['base_revision'], (int) $data['draft_version'], $data['message'] ?? '', $data['request_key']);

        return AdminResponse::success((new ContractProposalResource($result))->resolve($request));
    }

    public function show(Request $request, int $contract, int $proposal, ContractProposalService $service): JsonResponse
    {
        return AdminResponse::success((new ContractProposalResource($service->show($request->user(),
            (int) $request->attributes->get('current_organization_id'), $contract, $proposal)))->resolve($request));
    }

    public function decide(DecideContractProposalRequest $request, int $contract, int $proposal, ContractProposalService $service): JsonResponse
    {
        $data = $request->validated();
        $result = $service->decide($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal,
            (int) $data['expected_version'], $data['decision'], $data['reason'] ?? '', $data['request_key']);

        return AdminResponse::success((new ContractProposalResource($result))->resolve($request));
    }

    public function preview(Request $request, int $contract, int $proposal, ContractProposalService $service): JsonResponse
    {
        $result = $service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal);

        return AdminResponse::success(['proposal' => (new ContractProposalResource($result['proposal']))->resolve($request), 'html' => $result['html']]);
    }

    public function externalDecision(RecordExternalContractDecisionRequest $request, int $contract, int $proposal, ContractProposalService $service): JsonResponse
    {
        $data = $request->validated();
        $result = $service->decide($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $proposal,
            (int) $data['expected_version'], $data['decision'], $data['reason'] ?? '', $data['request_key'],
            ['basis' => $data['basis'], 'asset_id' => (int) $data['asset_id']]);

        return AdminResponse::success((new ContractProposalResource($result))->resolve($request));
    }
}
