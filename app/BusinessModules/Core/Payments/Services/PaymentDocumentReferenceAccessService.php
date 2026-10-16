<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class PaymentDocumentReferenceAccessService
{
    public function __construct(private readonly PaymentDocumentAccessService $access)
    {
    }

    public function assertInput(int $organizationId, User $actor, string $permission, array $data, ?int $existingProjectId = null): void
    {
        $sourceDocument = null;
        if ($permission === 'payments.invoice.create' && $existingProjectId === null
            && ! array_key_exists('project_id', $data)
            && ($data['document_type'] ?? null) === PaymentDocumentType::PAYMENT_ORDER->value
            && ($data['source_type'] ?? null) === PaymentDocument::class
            && ($sourceId = $this->id($data['source_id'] ?? null)) !== null) {
            $sourceDocument = PaymentDocument::query()->useWritePdo()->where('organization_id', $organizationId)->find($sourceId);
        }
        $derivesProject = $existingProjectId === null && (
            $this->id($data['contract_id'] ?? null) !== null
            || (($data['source_type'] ?? null) === Contract::class && $this->id($data['source_id'] ?? null) !== null)
            || (in_array($data['invoiceable_type'] ?? null, [Contract::class, ContractPerformanceAct::class], true)
                && $this->id($data['invoiceable_id'] ?? null) !== null)
            || $sourceDocument?->document_type === PaymentDocumentType::PAYMENT_REQUEST
        );
        $projectIds = [];
        if (! array_key_exists('project_id', $data)) {
            if (! $derivesProject) {
                $projectIds[] = $existingProjectId;
            }
        } elseif ($data['project_id'] === null || $data['project_id'] === '') {
            if (! $derivesProject) {
                $projectIds[] = null;
            }
        } elseif (($projectId = $this->id($data['project_id'])) !== null) {
            $projectIds[] = $projectId;
        }
        $checked = [];
        foreach ($projectIds as $projectId) {
            $this->access->assertProject($organizationId, $actor, $projectId, $permission);
            $checked[$projectId === null ? 'projectless' : (string) $projectId] = true;
        }

        if (($contractId = $this->id($data['contract_id'] ?? null)) !== null) {
            $contract = Contract::query()->useWritePdo()->where('organization_id', $organizationId)->find($contractId);
            if (! $contract instanceof Contract) {
                $this->notFound();
            }
            if ($contract->project_id === null && $contract->is_multi_project) {
                $this->access->contract($organizationId, $actor, (int) $contract->id, $permission);
            } else {
                $projectIds[] = $contract->project_id === null ? null : (int) $contract->project_id;
            }
        }
        if (($estimateId = $this->id($data['estimate_id'] ?? null)) !== null) {
            $estimate = Estimate::query()->useWritePdo()->where('organization_id', $organizationId)->find($estimateId);
            if (! $estimate instanceof Estimate) {
                $this->notFound();
            }
            $projectIds[] = $estimate->project_id === null ? null : (int) $estimate->project_id;
        }

        foreach (['source', 'invoiceable'] as $reference) {
            $id = $this->id($data[$reference.'_id'] ?? null);
            $type = $data[$reference.'_type'] ?? null;
            if ($id === null) {
                continue;
            }
            if ($type === Contract::class) {
                $contract = Contract::query()->useWritePdo()->where('organization_id', $organizationId)->find($id);
                if (! $contract instanceof Contract) {
                    $this->notFound();
                }
                if ($contract->project_id === null && $contract->is_multi_project) {
                    $this->access->contract($organizationId, $actor, (int) $contract->id, $permission);
                } else {
                    $projectIds[] = $contract->project_id === null ? null : (int) $contract->project_id;
                }
            } elseif ($type === ContractPerformanceAct::class) {
                $act = ContractPerformanceAct::query()->useWritePdo()->with('contract')
                    ->whereHas('contract', fn ($query) => $query->where('organization_id', $organizationId))->find($id);
                if (! $act instanceof ContractPerformanceAct) {
                    $this->notFound();
                }
                $projectId = $act->project_id ?? $act->contract?->project_id;
                if ($projectId === null && $act->contract?->is_multi_project) {
                    $this->access->contract($organizationId, $actor, (int) $act->contract_id, $permission);
                } else {
                    $projectIds[] = $projectId === null ? null : (int) $projectId;
                }
            } elseif ($type === PaymentDocument::class && $reference === 'source') {
                $source = $sourceDocument ?? PaymentDocument::query()->useWritePdo()->where('organization_id', $organizationId)->find($id);
                if (! $source instanceof PaymentDocument) {
                    $this->notFound();
                }
                $projectIds[] = $source->project_id === null ? null : (int) $source->project_id;
            }
        }

        $itemIds = [];
        $splits = $data['estimate_splits'] ?? [];
        foreach (is_array($splits) ? $splits : [] as $split) {
            if (is_array($split) && ($id = $this->id($split['estimate_item_id'] ?? null)) !== null) {
                $itemIds[$id] = $id;
            }
        }
        if ($itemIds !== []) {
            $items = EstimateItem::query()->useWritePdo()->with('estimate')->whereIn('id', array_values($itemIds))
                ->whereHas('estimate', fn ($query) => $query->where('organization_id', $organizationId))->get();
            if ($items->count() !== count($itemIds)) {
                $this->notFound();
            }
            foreach ($items as $item) {
                if (! $item->estimate instanceof Estimate) {
                    $this->notFound();
                }
                $projectIds[] = $item->estimate->project_id === null ? null : (int) $item->estimate->project_id;
            }
        }

        foreach ($projectIds as $projectId) {
            $key = $projectId === null ? 'projectless' : (string) $projectId;
            if (! isset($checked[$key])) {
                $this->access->assertProject($organizationId, $actor, $projectId, $permission);
                $checked[$key] = true;
            }
        }
    }

    private function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
    }
}
