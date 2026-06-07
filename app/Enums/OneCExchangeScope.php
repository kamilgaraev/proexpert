<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeScope: string
{
    case Counterparties = 'counterparties';
    case Employees = 'employees';
    case Organizations = 'organizations';
    case Projects = 'projects';
    case Contracts = 'contracts';
    case Materials = 'materials';
    case Nomenclature = 'nomenclature';
    case CostCategories = 'cost_categories';
    case CostCenters = 'cost_centers';
    case Warehouses = 'warehouses';
    case Acts = 'acts';
    case PaymentDocuments = 'payment_documents';
    case AdvanceTransactions = 'advance_transactions';
    case ProcurementDocuments = 'procurement_documents';
    case WarehouseDocuments = 'warehouse_documents';
}
