<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTOs\Contract\ContractDossierCreationInput;
use App\Http\Requests\Api\V1\Admin\Contract\CreateContractFromEstimateRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractFromEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class ContractFromEstimateController
{
    public function __construct(private readonly ContractFromEstimateService $service) {}

    public function store(CreateContractFromEstimateRequest $request, Project $project): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('auth.unauthorized'), 401);
            }
            $estimate = Estimate::query()
                ->whereKey($request->integer('estimate_id'))
                ->where('organization_id', $organizationId)
                ->where('project_id', $project->id)
                ->firstOrFail();
            $contract = $request->toDto();
            $result = $this->service->create(
                $organizationId,
                $actor,
                $project,
                $estimate,
                new ContractDossierCreationInput(
                    contract: $contract,
                    idempotencyKey: 'estimate:'.$estimate->id.':'.$request->string('idempotency_key')->toString(),
                    documentTitle: $request->validated('document_title') ?? 'Договор №'.$contract->number,
                    profileCode: $request->validated('document_profile_code') ?? 'contract.work',
                    documentMetadata: $request->validated('document_metadata') ?? [],
                    confidentialityLevel: $request->validated('document_confidentiality_level'),
                    sourceLinks: [[
                        'link_type' => 'estimate',
                        'linked_type' => 'estimate',
                        'linked_id' => (string) $estimate->id,
                        'display_name' => $estimate->number ?? $estimate->name,
                    ]],
                    sourceType: 'estimate',
                    sourceId: (string) $estimate->id,
                ),
                array_map('intval', $request->validated('estimate_item_ids')),
                $request->boolean('include_vat'),
            );

            return AdminResponse::success(
                new ContractResource($result->contract),
                null,
                $result->replayed ? 200 : 201,
            );
        } catch (\DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('contracts.create_from_estimate_failed', [
                'project_id' => $project->id,
                'estimate_id' => $request->integer('estimate_id'),
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contract.create_error'), 500);
        }
    }
}
