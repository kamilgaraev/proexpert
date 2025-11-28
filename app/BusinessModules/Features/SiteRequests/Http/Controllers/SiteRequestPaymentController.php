<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestPaymentService;
use App\BusinessModules\Features\SiteRequests\Http\Requests\CreatePaymentFromRequestsRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Core\Payments\Http\Resources\PaymentDocumentResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для создания платежей из заявок
 */
class SiteRequestPaymentController extends Controller
{
    public function __construct(
        private readonly SiteRequestPaymentService $paymentService
    ) {}

    /**
     * Получить список заявок, доступных для создания платежей
     *
     * @group SiteRequests Payment
     * @authenticated
     */
    public function getAvailableForPayment(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $filters = $request->only([
                'project_id',
                'request_type',
                'search',
            ]);

            $requests = $this->paymentService->getAvailableForPayment($organizationId, $filters);

            return response()->json([
                'success' => true,
                'data' => SiteRequestResource::collection($requests),
                'count' => $requests->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.payment.available.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить доступные заявки',
            ], 500);
        }
    }

    /**
     * Создать платеж из заявок
     *
     * @group SiteRequests Payment
     * @authenticated
     */
    public function createPayment(CreatePaymentFromRequestsRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = auth()->id();

            $validated = $request->validated();

            // Добавляем ID пользователя в данные платежа
            $validated['created_by_user_id'] = $userId;

            $paymentDocument = $this->paymentService->createPaymentFromRequests(
                $organizationId,
                $validated['request_ids'],
                $validated
            );

            return response()->json([
                'success' => true,
                'message' => 'Платеж успешно создан из заявок',
                'data' => new PaymentDocumentResource($paymentDocument),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('site_requests.payment.create.error', [
                'request_data' => $request->validated(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать платеж из заявок',
            ], 500);
        }
    }
}

