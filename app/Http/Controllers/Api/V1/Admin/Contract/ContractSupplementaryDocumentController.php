<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\ActivateContractSupplementaryDocumentRequest;
use App\Http\Requests\Api\V1\Admin\Contract\CancelContractActivationRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ConfirmContractRevisionRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PrepareContractSupplementaryArchiveRequest;
use App\Http\Requests\Api\V1\Admin\Contract\RecordExternalContractConfirmationRequest;
use App\Http\Requests\Api\V1\Admin\Contract\SaveContractSupplementaryDraftRequest;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractSupplementaryDocumentRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractSupplementaryActivationService;
use App\Services\Contract\ContractSupplementaryApplicationService;
use App\Services\Contract\ContractSupplementaryConfirmationService;
use App\Services\Contract\ContractSupplementaryDocumentService;
use App\Services\Contract\ContractSupplementaryLegalArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractSupplementaryDocumentController extends Controller
{
    public function index(Request $request, int $contract, ContractSupplementaryDocumentService $service): JsonResponse
    {
        return AdminResponse::success($service->list($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function store(StoreContractSupplementaryDocumentRequest $request, int $contract, ContractSupplementaryDocumentService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->create(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            $data['template_id'], (int) $data['template_version'], $data['number'], $data['agreement_date'], $data['request_key']
        ));
    }

    public function show(Request $request, int $contract, int $document, ContractSupplementaryDocumentService $service): JsonResponse
    {
        return AdminResponse::success($service->show($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document));
    }

    public function draft(SaveContractSupplementaryDraftRequest $request, int $contract, int $document, ContractSupplementaryDocumentService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->saveDraft(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document,
            $data['values'], (int) $data['expected_version'], $data['request_key']
        ));
    }

    public function preview(Request $request, int $contract, int $document, ContractSupplementaryDocumentService $service): JsonResponse
    {
        return AdminResponse::success($service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document));
    }

    public function confirmations(Request $request, int $contract, int $document, ContractSupplementaryConfirmationService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document));
    }

    public function confirm(ConfirmContractRevisionRequest $request, int $contract, int $document, ContractSupplementaryConfirmationService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->confirm(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document,
            $data['content_hash'], $data['request_key']
        ));
    }

    public function externalConfirmation(RecordExternalContractConfirmationRequest $request, int $contract, int $document, ContractSupplementaryConfirmationService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->confirm(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document,
            $data['content_hash'], $data['request_key'],
            ['basis' => $data['basis'], 'asset_id' => (int) $data['asset_id'], 'side' => $data['side']]
        ));
    }

    public function legalArchiveShow(Request $request, int $contract, int $document, ContractSupplementaryLegalArchiveService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document));
    }

    public function legalArchiveStore(PrepareContractSupplementaryArchiveRequest $request, int $contract, int $document, ContractSupplementaryLegalArchiveService $service): JsonResponse
    {
        return AdminResponse::success($service->prepare(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document,
            $request->validated('content_hash')
        ));
    }

    public function activationPreview(Request $request, int $contract, int $document, ContractSupplementaryActivationService $service): JsonResponse
    {
        return AdminResponse::success($service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document));
    }

    public function activate(
        ActivateContractSupplementaryDocumentRequest $request,
        int $contract,
        int $document,
        ContractSupplementaryActivationService $service,
        ContractSupplementaryApplicationService $applications
    ): JsonResponse {
        $data = $request->validated();
        $activation = $service->schedule(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document,
            $data['content_hash'],
            $data['previous_document_id'] === null ? null : (int) $data['previous_document_id'],
            $data['effective_date'], $data['basis'], $data['request_key']
        );

        return AdminResponse::success($applications->applyDue($activation['id']));
    }

    public function retry(Request $request, int $contract, int $document, int $activation, ContractSupplementaryApplicationService $service): JsonResponse
    {
        return AdminResponse::success($service->retry(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document, $activation
        ));
    }

    public function cancelActivation(CancelContractActivationRequest $request, int $contract, int $document, int $activation, ContractSupplementaryActivationService $service): JsonResponse
    {
        return AdminResponse::success($service->cancel(
            $request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $document, $activation,
            $request->validated('basis'), $request->validated('request_key')
        ));
    }
}
