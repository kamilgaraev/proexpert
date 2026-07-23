<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\Http\Requests\Api\V1\Admin\Contract\MutateContractLegalDossierRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Http\Requests\Api\V1\Admin\Contract\ListContractLegalDossierCandidatesRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\ContractLegalDossierCandidateResource;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractLegalDossierService;
use Illuminate\Http\JsonResponse;
use Throwable;

use function trans_message;

final class ContractLegalDossierController extends LegalArchiveApiController
{
    public function __construct(private readonly ContractLegalDossierService $dossiers) {}

    public function store(
        MutateContractLegalDossierRequest $request,
        int $project,
        int $contract,
    ): JsonResponse {
        try {
            $actor = $this->actor($request);
            $organizationId = $this->organizationId($request);
            $data = $request->validated();
            $result = $data['action'] === 'create'
                ? $this->dossiers->create($actor, $organizationId, $project, $contract, $data)
                : $this->dossiers->attach($actor, $organizationId, $project, $contract, (int) $data['document_id']);

            return AdminResponse::success(
                [
                    'contract' => new ContractResource($result->contract),
                    'legal_document' => new LegalArchiveDocumentResource($result->document),
                    'operation_result' => $result->operationResult,
                ],
                trans_message('legal_archive.messages.contract_dossier_'.$result->operationResult),
                $result->operationResult === 'replayed' ? 200 : 201,
            );
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'contract_legal_dossier_store', [
                'project_id' => $project,
                'contract_id' => $contract,
            ]);
        }
    }

    public function candidates(
        ListContractLegalDossierCandidatesRequest $request,
        int $project,
        int $contract,
    ): JsonResponse {
        try {
            $documents = $this->dossiers->candidates(
                $this->actor($request),
                $this->organizationId($request),
                $project,
                $contract,
                $request->validated(),
            );

            return AdminResponse::paginated(
                ContractLegalDossierCandidateResource::collection($documents->getCollection()),
                [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
                trans_message('legal_archive.messages.contract_dossier_candidates_loaded'),
            );
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'contract_legal_dossier_candidates', [
                'project_id' => $project,
                'contract_id' => $contract,
            ]);
        }
    }
}
