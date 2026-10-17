<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Illuminate\Database\Eloquent\Builder;

final class AssistantPaymentDocumentChainReportProjection
{
    public function apply(Builder $documents, Builder $parents, Builder $contracts, ?Builder $acts, int $organizationId): Builder
    {
        $parents = (clone $parents)->where('payment_documents.organization_id', $organizationId)
            ->select(['payment_documents.id', 'payment_documents.project_id', 'payment_documents.invoiceable_type',
                'payment_documents.invoiceable_id', 'payment_documents.source_type', 'payment_documents.source_id', 'payment_documents.metadata']);
        $contracts = (clone $contracts)->select(['contracts.id', 'contracts.number', 'contracts.project_id', 'contracts.is_multi_project']);
        $actContract = $acts === null ? null : (clone $acts)->select(['contract_performance_acts.id', 'contract_performance_acts.contract_id']);
        $parentSql = $parents->toSql();
        $actSql = static fn (string $reference): string => $actContract === null ? 'NULL'
            : '(SELECT report_payment_act.contract_id FROM ('.$actContract->toSql().') report_payment_act WHERE report_payment_act.id = chain.'.$reference.' LIMIT 1)';
        $metadataContract = "CASE WHEN chain.metadata->>'contract_id' ~ '^[0-9]{1,19}$'"
            ." THEN CASE WHEN (chain.metadata->>'contract_id')::numeric BETWEEN 1 AND 9223372036854775807"
            ." THEN (chain.metadata->>'contract_id')::bigint ELSE NULL END ELSE NULL END";
        $identity = 'CASE WHEN chain.invoiceable_type = ? AND chain.invoiceable_id > 0 THEN chain.invoiceable_id'
            .' WHEN chain.invoiceable_type = ? AND chain.invoiceable_id > 0 THEN '.$actSql('invoiceable_id')
            .' WHEN chain.source_type = ? AND chain.source_id > 0 THEN chain.source_id'
            .' WHEN chain.source_type = ? AND chain.source_id > 0 THEN '.$actSql('source_id').' ELSE '.$metadataContract.' END';
        $chain = 'WITH RECURSIVE assistant_report_payment_chain AS ('
            .' SELECT root.*, 0 AS depth, ARRAY[root.id] AS visited FROM ('.$parentSql.') root WHERE root.id = payment_documents.id'
            .' UNION ALL SELECT parent.*, chain.depth + 1, chain.visited || parent.id FROM ('.$parentSql.') parent'
            .' JOIN assistant_report_payment_chain chain ON chain.source_type = ? AND chain.source_id = parent.id'
            .' AND chain.project_id IS NOT DISTINCT FROM parent.project_id'
            .' WHERE chain.depth < 32 AND NOT parent.id = ANY(chain.visited)'
            .' AND (chain.invoiceable_id IS NULL OR chain.invoiceable_id < 1 OR chain.invoiceable_type IS NULL OR chain.invoiceable_type NOT IN (?, ?))'
            .') SELECT %s FROM assistant_report_payment_chain chain JOIN ('.$contracts->toSql().') report_payment_contract'
            .' ON report_payment_contract.id = ('.$identity.')'
            .' WHERE (NOT COALESCE(report_payment_contract.is_multi_project, FALSE)'
            .' AND report_payment_contract.project_id IS NOT DISTINCT FROM chain.project_id)'
            .' OR (COALESCE(report_payment_contract.is_multi_project, FALSE) AND chain.project_id IS NOT NULL'
            .' AND EXISTS (SELECT 1 FROM contract_project WHERE contract_project.contract_id = report_payment_contract.id'
            .' AND contract_project.project_id = chain.project_id)) ORDER BY chain.depth ASC LIMIT 1';
        $bindings = [...$parents->getBindings(), ...$parents->getBindings(), PaymentDocument::class, Contract::class, ContractPerformanceAct::class,
            ...$contracts->getBindings(), Contract::class, ContractPerformanceAct::class, ...($actContract?->getBindings() ?? []),
            Contract::class, ContractPerformanceAct::class, ...($actContract?->getBindings() ?? [])];
        $documents->select('payment_documents.*');
        foreach (['id' => 'contract_id', 'number' => 'contract_number'] as $field => $alias) {
            $documents->selectRaw('('.sprintf($chain, 'report_payment_contract.'.$field).') AS '.$alias, $bindings);
        }

        return $documents->getModel()->newModelQuery()->fromSub($documents, 'payment_documents');
    }
}
