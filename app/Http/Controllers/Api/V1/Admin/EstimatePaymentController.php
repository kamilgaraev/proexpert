<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimatePaymentController extends Controller
{
    /**
     * Получить список платежных документов по смете
     */
    public function getPayments(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $payments = PaymentDocument::query()
            ->where('organization_id', $organizationId)
            ->where('estimate_id', $estimateId)
            ->with(['payeeContractor', 'payerOrganization'])
            ->orderBy('document_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'document_date' => $payment->document_date->format('Y-m-d'),
                    'document_number' => $payment->document_number,
                    'payment_type' => $payment->document_type->value,
                    'amount' => (float) $payment->amount,
                    'status' => $payment->status->value,
                    'contractor' => $payment->payeeContractor ? [
                        'id' => $payment->payeeContractor->id,
                        'name' => $payment->payeeContractor->name,
                    ] : null,
                ];
            }),
        ]);
    }
}

