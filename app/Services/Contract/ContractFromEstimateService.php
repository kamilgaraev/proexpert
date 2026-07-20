<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\DTOs\Contract\ContractDossierCreationResult;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final class ContractFromEstimateService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ContractDossierCreationService $dossiers,
        private readonly ContractEstimateService $estimates,
    ) {}

    public function create(
        int $organizationId,
        User $actor,
        Project $project,
        Estimate $estimate,
        ContractDossierCreationInput $input,
        array $itemIds,
        bool $includeVat,
    ): ContractDossierCreationResult {
        if ((int) $project->organization_id !== $organizationId
            || (int) $estimate->organization_id !== $organizationId
            || (int) $estimate->project_id !== (int) $project->id
            || (int) $input->contract->project_id !== (int) $project->id) {
            throw new DomainException('contract_estimate_context_invalid');
        }

        return $this->connection->transaction(function () use ($organizationId, $actor, $estimate, $input, $itemIds, $includeVat): ContractDossierCreationResult {
            $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
            $availableItems = EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereIn('id', $itemIds)
                ->count();
            if ($itemIds === [] || $availableItems !== count($itemIds)) {
                throw new DomainException('contract_estimate_items_invalid');
            }
            $result = $this->dossiers->create($organizationId, $actor, $input);
            if (! $result->replayed) {
                $this->estimates->attachItems($result->contract, $estimate, $itemIds, $includeVat);
            }

            return $result;
        });
    }
}
