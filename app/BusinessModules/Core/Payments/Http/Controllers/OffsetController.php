<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\OffsetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OffsetController extends Controller
{
    public function __construct(
        private readonly OffsetService $offsetService
    ) {}

    /**
     * Получить возможности для взаимозачета
     */
    public function opportunities(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'contractor_id' => 'required|integer|exists:contractors,id',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            
            $opportunities = $this->offsetService->getOffsetOpportunities(
                $organizationId,
                $validated['contractor_id']
            );

            return response()->json([
                'success' => true,
                'data' => $opportunities,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Выполнить взаимозачет
     */
    public function perform(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'receivable_id' => 'required|integer|exists:payment_documents,id',
                'payable_id' => 'required|integer|exists:payment_documents,id',
                'amount' => 'required|numeric|min:0.01',
                'notes' => 'nullable|string|max:500',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            
            $receivable = PaymentDocument::findOrFail($validated['receivable_id']);
            $payable = PaymentDocument::findOrFail($validated['payable_id']);

            // Проверка принадлежности к организации
            if ($receivable->organization_id !== $organizationId || $payable->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Документы не принадлежат текущей организации',
                ], 403);
            }

            $result = $this->offsetService->performOffset(
                $receivable,
                $payable,
                $validated['amount'],
                $validated['notes'] ?? ''
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Взаимозачет выполнен успешно',
            ]);

        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('offset.perform.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось выполнить взаимозачет',
            ], 500);
        }
    }

    /**
     * Автоматический взаимозачет для контрагента
     */
    public function auto(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'contractor_id' => 'required|integer|exists:contractors,id',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            
            $result = $this->offsetService->autoOffsetForContractor(
                $organizationId,
                $validated['contractor_id']
            );

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
                'message' => $result['message'],
            ]);

        } catch (\Exception $e) {
            \Log::error('offset.auto.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось выполнить автоматический взаимозачет',
            ], 500);
        }
    }
}

