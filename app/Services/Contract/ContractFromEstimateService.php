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
        private readonly \App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAccess $financeAccess,
    ) {}

    public function create(
        int $organizationId,
        User $actor,
        Project $project,
        Estimate $estimate,
        ContractDossierCreationInput $input,
        array $itemIds,
        bool $includeVat,
        ?string $vatRate = null,
    ): ContractDossierCreationResult {
        if ((int) $project->organization_id !== $organizationId
            || (int) $estimate->organization_id !== $organizationId
            || (int) $estimate->project_id !== (int) $project->id
            || (int) $input->contract->project_id !== (int) $project->id) {
            throw new DomainException('contract_estimate_context_invalid');
        }

        return $this->connection->transaction(function () use ($organizationId, $actor, $estimate, $input, $itemIds, $includeVat, $vatRate): ContractDossierCreationResult {
            $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
            sort($itemIds);
            $availableItems = EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereIn('id', $itemIds)
                ->count();
            if ($itemIds === [] || $availableItems !== count($itemIds)) {
                throw new DomainException('contract_estimate_items_invalid');
            }
            $this->financeAccess->estimate($actor, (int) $estimate->project_id, (int) $estimate->id, true);
            $estimate = Estimate::query()->whereKey($estimate->id)->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
            $operationId = (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL,
                'most:estimate-contract:'.$estimate->id.':'.$input->normalizedIdempotencyKey());
            $hash = hash('sha256', json_encode([
                'contract' => $input->contract->toArray(), 'items' => $itemIds, 'include_vat' => $includeVat,
                'vat_rate' => $includeVat && $vatRate !== null ? \App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal::value($vatRate, 8) : null,
                'title' => $input->documentTitle, 'profile' => $input->profileCode, 'metadata' => $input->documentMetadata,
                'confidentiality' => $input->confidentialityLevel, 'links' => $input->sourceLinks,
            ], JSON_THROW_ON_ERROR));
            $receiptQuery = $this->connection->table('estimate_finance_mutations')
                ->where('estimate_id', $estimate->id)->where('mutation_id', $operationId);
            $receipt = $receiptQuery->first();
            if ($receipt && ($receipt->request_hash !== $hash || (int) $receipt->actor_id !== (int) $actor->id)) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            $input = new ContractDossierCreationInput(
                contract: $input->contract, idempotencyKey: $input->idempotencyKey, documentTitle: $input->documentTitle,
                profileCode: $input->profileCode, documentMetadata: $input->documentMetadata,
                confidentialityLevel: $input->confidentialityLevel, sourceLinks: $input->sourceLinks,
                sourceType: 'estimate_contract_creation', sourceId: $operationId,
            );
            $result = $this->dossiers->create($organizationId, $actor, $input);
            if ($result->replayed && ! $receipt) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            if ($receipt && (int) (json_decode($receipt->changes, true, 512, JSON_THROW_ON_ERROR)['created_contract_id'] ?? 0) !== (int) $result->contract->id) {
                throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(trans_message('estimate_finance.conflict'));
            }
            $this->financeAccess->editContracts($actor, $estimate, [], [(int) $result->contract->id]);
            if (! $result->replayed) {
                $this->estimates->attachItems($result->contract, $estimate, $itemIds, $includeVat, $actor, $vatRate);
                $this->connection->table('estimate_finance_mutations')->insert([
                    'estimate_id' => $estimate->id, 'mutation_id' => $operationId, 'request_hash' => $hash,
                    'actor_id' => $actor->id, 'revision' => (int) $estimate->fresh()->finance_revision,
                    'changes' => json_encode(['created_contract_id' => $result->contract->id], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $result;
        });
    }
}
