<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PaymentDocumentQueryService
{
    private const DETAIL_RELATIONS = [
        'project',
        'payerOrganization',
        'payeeOrganization',
        'payerContractor',
        'payeeContractor',
        'approvals',
        'transactions',
        'siteRequests',
        'budgetArticle',
        'responsibilityCenter',
        'estimateSplits.estimateItem.measurementUnit',
    ];

    private const FALLBACK_DETAIL_RELATIONS = [
        'project',
        'payerOrganization',
        'payeeOrganization',
        'payerContractor',
        'payeeContractor',
        'approvals',
        'transactions',
        'siteRequests',
        'budgetArticle',
        'responsibilityCenter',
        'estimateSplits.estimateItem',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PaymentDocument>
     */
    public function listForOrganization(int $organizationId, array $filters): LengthAwarePaginator
    {
        $query = PaymentDocument::forOrganization($organizationId)
            ->with([
                'project',
                'payerOrganization',
                'payeeOrganization',
                'payerContractor',
                'payeeContractor',
                'budgetArticle',
                'responsibilityCenter',
            ]);

        if (Schema::hasTable('site_requests') && Schema::hasTable('payment_document_site_requests')) {
            $query->withCount('siteRequests');
        }

        $this->applyFilters($query, $filters);

        $sortBy = is_string($filters['sort_by'] ?? null) ? $filters['sort_by'] : 'created_at';
        $sortOrder = ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 100)));

        return $query->orderBy($sortBy, $sortOrder)->paginate(
            $perPage,
            ['*'],
            'page',
            max(1, (int) ($filters['page'] ?? 1))
        );
    }

    public function findDetailed(int $organizationId, int|string $id): PaymentDocument
    {
        try {
            $document = PaymentDocument::forOrganization($organizationId)
                ->with(self::DETAIL_RELATIONS)
                ->findOrFail($id);
        } catch (\Error) {
            $document = PaymentDocument::forOrganization($organizationId)
                ->with(self::FALLBACK_DETAIL_RELATIONS)
                ->where(function (Builder $query): void {
                    $query->whereNull('invoiceable_type')
                        ->orWhere('invoiceable_type', '!=', 'App\\BusinessModules\\Core\\Payments\\Models\\Invoice')
                        ->orWhere('invoiceable_type', 'NOT LIKE', '%Payments\\\\Models\\\\Invoice%');
                })
                ->findOrFail($id);
        }

        $this->loadSafeMorphRelation($document, 'invoiceable', $document->invoiceable_type);
        $this->loadSafeMorphRelation($document, 'source', $document->source_type);

        return $document;
    }

    public function findForWorkflow(int $organizationId, int|string $id): PaymentDocument
    {
        return PaymentDocument::forOrganization($organizationId)->findOrFail($id);
    }

    public function findForPrintOrder(int $organizationId, int|string $id): PaymentDocument
    {
        return PaymentDocument::forOrganization($organizationId)
            ->with(['payerOrganization', 'payeeOrganization', 'payerContractor', 'payeeContractor'])
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, PaymentDocument>
     */
    public function overdue(int $organizationId): Collection
    {
        return PaymentDocument::forOrganization($organizationId)
            ->overdue()
            ->with(['project', 'payeeContractor'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * @return Collection<int, PaymentDocument>
     */
    public function upcoming(int $organizationId, int $days): Collection
    {
        return PaymentDocument::forOrganization($organizationId)
            ->upcoming($days)
            ->with(['project', 'payeeContractor'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function statistics(int $organizationId): array
    {
        $documents = PaymentDocument::forOrganization($organizationId)->get();

        return [
            'total_count' => $documents->count(),
            'total_amount' => $documents->sum('amount'),
            'paid_amount' => $documents->sum('paid_amount'),
            'remaining_amount' => $documents->sum('remaining_amount'),
            'by_status' => [
                'draft' => $documents->where('status', PaymentDocumentStatus::DRAFT)->count(),
                'pending_approval' => $documents->where('status', PaymentDocumentStatus::PENDING_APPROVAL)->count(),
                'approved' => $documents->where('status', PaymentDocumentStatus::APPROVED)->count(),
                'scheduled' => $documents->where('status', PaymentDocumentStatus::SCHEDULED)->count(),
                'paid' => $documents->where('status', PaymentDocumentStatus::PAID)->count(),
                'partially_paid' => $documents->where('status', PaymentDocumentStatus::PARTIALLY_PAID)->count(),
                'rejected' => $documents->where('status', PaymentDocumentStatus::REJECTED)->count(),
                'cancelled' => $documents->where('status', PaymentDocumentStatus::CANCELLED)->count(),
            ],
            'by_type' => [
                'payment_request' => $documents->where('document_type', PaymentDocumentType::PAYMENT_REQUEST)->count(),
                'invoice' => $documents->where('document_type', PaymentDocumentType::INVOICE)->count(),
                'payment_order' => $documents->where('document_type', PaymentDocumentType::PAYMENT_ORDER)->count(),
                'incoming_payment' => $documents->where('document_type', PaymentDocumentType::INCOMING_PAYMENT)->count(),
                'expense' => $documents->where('document_type', PaymentDocumentType::EXPENSE)->count(),
                'offset_act' => $documents->where('document_type', PaymentDocumentType::OFFSET_ACT)->count(),
            ],
            'overdue_count' => $documents->filter(fn (PaymentDocument $document): bool => $document->isOverdue())->count(),
            'overdue_amount' => $documents->filter(fn (PaymentDocument $document): bool => $document->isOverdue())->sum('remaining_amount'),
        ];
    }

    /**
     * @param  Collection<int, PaymentDocument>|EloquentCollection<int, PaymentDocument>  $documents
     * @return array<string, mixed>
     */
    public function summary(Collection|EloquentCollection $documents): array
    {
        return [
            'total' => $documents->count(),
            'total_amount' => (float) $documents->sum('amount'),
            'remaining_amount' => (float) $documents->sum('remaining_amount'),
            'by_status' => $documents
                ->groupBy(fn (PaymentDocument $document): string => $document->status->value)
                ->map(fn (Collection $items): array => [
                    'count' => $items->count(),
                    'amount' => (float) $items->sum('amount'),
                ])
                ->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['document_type'])) {
            $query->byType(PaymentDocumentType::from($filters['document_type']));
        }

        if (isset($filters['status'])) {
            $query->byStatus(PaymentDocumentStatus::from($filters['status']));
        }

        if (isset($filters['project_id'])) {
            $query->forProject((int) $filters['project_id']);
        }

        if (isset($filters['purchase_order_id'])) {
            $this->applyPurchaseOrderFilter($query, (int) $filters['purchase_order_id']);
        }

        if (isset($filters['contract_id'])) {
            $this->applyContractFilter($query, (int) $filters['contract_id']);
        }

        if (isset($filters['estimate_id'])) {
            $estimateId = (int) $filters['estimate_id'];
            $query->whereHas('estimateSplits.estimateItem', fn (Builder $estimateItemQuery) => $estimateItemQuery->where('estimate_id', $estimateId));
        }

        if (isset($filters['date_from'])) {
            $query->where('document_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('document_date', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_from'])) {
            $query->where('amount', '>=', $filters['amount_from']);
        }

        if (isset($filters['amount_to'])) {
            $query->where('amount', '<=', $filters['amount_to']);
        }

        if (isset($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('document_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('payment_purpose', 'like', "%{$search}%");
            });
        }
    }

    private function applyContractFilter(Builder $query, int $contractId): void
    {
        $query->where(function (Builder $contractQuery) use ($contractId): void {
            $contractQuery->where(function (Builder $directQuery) use ($contractId): void {
                $directQuery->where('invoiceable_type', Contract::class)
                    ->where('invoiceable_id', $contractId);
            })->orWhere(function (Builder $actQuery) use ($contractId): void {
                $actQuery->where('invoiceable_type', \App\Models\ContractPerformanceAct::class)
                    ->whereExists(function ($existsQuery) use ($contractId): void {
                        $existsQuery->select(DB::raw(1))
                            ->from('contract_performance_acts')
                            ->whereColumn('contract_performance_acts.id', 'payment_documents.invoiceable_id')
                            ->where('contract_performance_acts.contract_id', $contractId);
                    });
            });
        });
    }

    private function applyPurchaseOrderFilter(Builder $query, int $purchaseOrderId): void
    {
        $query->where(function (Builder $paymentQuery) use ($purchaseOrderId): void {
            $paymentQuery
                ->where('metadata->purchase_order_id', $purchaseOrderId)
                ->orWhere('metadata->purchase_order_id', (string) $purchaseOrderId)
                ->orWhereExists(function ($existsQuery) use ($purchaseOrderId): void {
                    $existsQuery
                        ->select(DB::raw(1))
                        ->from('purchase_orders')
                        ->where('purchase_orders.id', $purchaseOrderId)
                        ->whereColumn('purchase_orders.organization_id', 'payment_documents.organization_id')
                        ->whereNotNull('purchase_orders.contract_id')
                        ->where('payment_documents.invoice_type', InvoiceType::MATERIAL_PURCHASE->value)
                        ->where(function ($contractQuery): void {
                            $contractQuery
                                ->where(function ($sourceQuery): void {
                                    $sourceQuery
                                        ->where('payment_documents.source_type', Contract::class)
                                        ->whereColumn('payment_documents.source_id', 'purchase_orders.contract_id');
                                })
                                ->orWhere(function ($invoiceableQuery): void {
                                    $invoiceableQuery
                                        ->where('payment_documents.invoiceable_type', Contract::class)
                                        ->whereColumn('payment_documents.invoiceable_id', 'purchase_orders.contract_id');
                                });
                        });
                });
        });
    }

    private function loadSafeMorphRelation(PaymentDocument $document, string $relation, mixed $type): void
    {
        if (! is_string($type) || $this->resolveMorphClass($type) === null) {
            return;
        }

        try {
            $document->loadMissing($relation);
        } catch (Throwable $e) {
            Log::debug('payment_document.morph_load_failed', [
                'document_id' => $document->id,
                'relation' => $relation,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveMorphClass(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
