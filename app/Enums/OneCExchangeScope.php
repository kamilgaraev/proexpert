<?php

declare(strict_types=1);

namespace App\Enums;

enum OneCExchangeScope: string
{
    case Counterparties = 'counterparties';
    case Employees = 'employees';
    case Projects = 'projects';
    case Materials = 'materials';
    case CostCategories = 'cost_categories';
    case Acts = 'acts';
    case PaymentDocuments = 'payment_documents';
    case AdvanceTransactions = 'advance_transactions';
    case ProcurementDocuments = 'procurement_documents';
}
