<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Services\PaymentRequestService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly PaymentRequestService $requestService
    ) {}

    /**
     * Получить входящие платежные требования
     */
    public function incoming(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $filters = [
                'status' => $request->input('status'),
                'project_id' => $request->input('project_id'),
                'contractor_id' => $request->input('contractor_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
            ];

            $requests = $this->requestService->getIncomingRequests($organizationId, $filters);

            return response()->json([
                'success' => true,
                'data' => $requests->map(fn($doc) => [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'document_date' => $doc->document_date->format('Y-m-d'),
                    'due_date' => $doc->due_date?->format('Y-m-d'),
                    'status' => $doc->status->value,
                    'status_label' => $doc->status->label(),
                    'amount' => $doc->amount,
                    'currency' => $doc->currency,
                    'contractor' => [
                        'id' => $doc->payee_contractor_id,
                        'name' => $doc->getPayeeName(),
                    ],
                    'description' => $doc->description,
                    'created_at' => $doc->created_at->toDateTimeString(),
                ]),
                'meta' => [
                    'total' => $requests->count(),
                    'total_amount' => $requests->sum('amount'),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_request.incoming.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить входящие требования',
            ], 500);
        }
    }

    /**
     * Создать платежное требование
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'contractor_id' => 'required|integer|exists:contractors,id',
                'project_id' => 'nullable|integer|exists:projects,id',
                'contract_id' => 'nullable|integer|exists:contracts,id',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'vat_rate' => 'nullable|numeric|min:0|max:100',
                'document_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'description' => 'nullable|string',
                'bank_account' => 'nullable|string|size:20',
                'bank_bik' => 'nullable|string|size:9',
                'bank_correspondent_account' => 'nullable|string|size:20',
                'bank_name' => 'nullable|string',
                'attached_documents' => 'nullable|array',
                'metadata' => 'nullable|array',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->user()->id;

            $validated['organization_id'] = $organizationId;
            $validated['created_by_user_id'] = $userId;

            $document = $this->requestService->createFromContractor($validated);

            return response()->json([
                'success' => true,
                'message' => 'Платежное требование создано',
                'data' => [
                    'id' => $document->id,
                    'document_number' => $document->document_number,
                    'status' => $document->status->value,
                ],
            ], 201);
        } catch (\DomainException | \InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_request.store.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать требование',
            ], 500);
        }
    }

    /**
     * Принять платежное требование
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scheduled_at' => 'nullable|date',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            
            $document = \App\BusinessModules\Core\Payments\Models\PaymentDocument::forOrganization($organizationId)
                ->findOrFail($id);

            $paymentOrder = $this->requestService->acceptRequest($document, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Требование принято, создано платежное поручение',
                'data' => [
                    'request_id' => $document->id,
                    'payment_order_id' => $paymentOrder->id,
                    'payment_order_number' => $paymentOrder->document_number,
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_request.accept.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось принять требование',
            ], 500);
        }
    }

    /**
     * Отклонить платежное требование
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|min:3',
            ]);

            $organizationId = $request->attributes->get('current_organization_id');
            
            $document = \App\BusinessModules\Core\Payments\Models\PaymentDocument::forOrganization($organizationId)
                ->findOrFail($id);

            $this->requestService->rejectRequest($document, $validated['reason'], $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Требование отклонено',
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('payment_request.reject.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось отклонить требование',
            ], 500);
        }
    }

    /**
     * Получить требования от контрагента
     */
    public function fromContractor(Request $request, int $contractorId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $requests = $this->requestService->getRequestsFromContractor($organizationId, $contractorId);

            return response()->json([
                'success' => true,
                'data' => $requests->map(fn($doc) => [
                    'id' => $doc->id,
                    'document_number' => $doc->document_number,
                    'document_date' => $doc->document_date->format('Y-m-d'),
                    'status' => $doc->status->value,
                    'status_label' => $doc->status->label(),
                    'amount' => $doc->amount,
                    'description' => $doc->description,
                ]),
                'meta' => [
                    'total' => $requests->count(),
                    'total_amount' => $requests->sum('amount'),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_request.from_contractor.error', [
                'contractor_id' => $contractorId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить требования',
            ], 500);
        }
    }

    /**
     * Статистика по платежным требованиям
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $stats = $this->requestService->getStatistics($organizationId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            \Log::error('payment_request.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }
}

