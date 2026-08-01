<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Contracts;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPreparedSelection;
use DateTimeImmutable;

interface ProcurementAwardOwnerEventWriter
{
    public function prepareForSupplierRequest(
        SupplierRequest $supplierRequest,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection;

    public function prepareForPurchaseRequest(
        PurchaseRequest $purchaseRequest,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection;

    public function selected(
        ProcurementAwardPreparedSelection $prepared,
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        ?string $reason,
    ): void;

    public function approved(
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void;

    public function rejected(
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void;

    public function committed(
        SupplierProposalDecision $decision,
        SupplierProposalVersion $acceptedVersion,
        PurchaseOrder $order,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void;
}
