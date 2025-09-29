<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Http\Resources\Billing\OrganizationBalanceResource; // Создадим
use App\Http\Resources\Billing\BalanceTransactionResource; // Создадим
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // Для тайпхинта
use App\Models\Payment; // <-- Правильный импорт
use App\Exceptions\Billing\BalanceException;
use Illuminate\Support\Facades\Log; // <-- Правильный импорт

class BalanceController extends Controller
{
    protected BalanceServiceInterface $balanceService;
    protected PaymentGatewayInterface $paymentGateway;

    public function __construct(BalanceServiceInterface $balanceService, PaymentGatewayInterface $paymentGateway)
    {
        $this->balanceService = $balanceService;
        $this->paymentGateway = $paymentGateway;
    }

    /**
     * @OA\Get(
     *     path="/api/billing/balance",
     *     summary="Получить текущий баланс организации пользователя",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Текущий баланс организации",
     *         @OA\JsonContent(ref="#/components/schemas/OrganizationBalanceResource")
     *     ),
     *     @OA\Response(response=401, description="Не авторизован"),
     *     @OA\Response(response=404, description="Организация не найдена или не привязана к пользователю")
     * )
     */
    public function show(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Organization not found for this user.'], 404);
        }

        // Кешируем баланс на 30 секунд для избежания повторных запросов
        $balance = \Illuminate\Support\Facades\Cache::remember(
            "organization_balance_{$organizationId}", 
            30, 
            function () use ($organizationId) {
                $organization = \App\Models\Organization::find($organizationId);
                return $organization ? $this->balanceService->getOrCreateOrganizationBalance($organization) : null;
            }
        );
        
        if (!$balance) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        return new OrganizationBalanceResource($balance);
    }

    /**
     * @OA\Get(
     *     path="/api/billing/balance/transactions",
     *     summary="Получить историю транзакций по балансу организации",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *      @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Количество записей на страницу",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Список транзакций",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/BalanceTransactionResource"))
     *     ),
     *     @OA\Response(response=401, description="Не авторизован"),
     *     @OA\Response(response=404, description="Организация не найдена")
     * )
     */
    public function getTransactions(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $organization = $user->currentOrganization;

        if (!$organization) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
        $transactions = $balance->transactions()->latest()->paginate($request->input('limit', 15));

        return BalanceTransactionResource::collection($transactions);
    }

    /**
     * @OA\Post(
     *     path="/api/billing/balance/top-up",
     *     summary="Инициировать пополнение баланса организации",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "currency"},
     *             @OA\Property(property="amount", type="number", format="float", example=1000.00, description="Сумма пополнения в основной валюте (рублях)"),
     *             @OA\Property(property="currency", type="string", example="RUB", description="Валюта пополнения"),
     *             @OA\Property(property="payment_method_token", type="string", nullable=true, example="tok_mock_visa", description="Токен метода оплаты от платежного шлюза")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Ответ от платежного шлюза (например, с redirect_url или статус)",
     *         @OA\JsonContent(ref="#/components/schemas/PaymentGatewayChargeResponse") 
     *     ),
     *     @OA\Response(response=400, description="Ошибка операции (например, неверная сумма)"),
     *     @OA\Response(response=401, description="Не авторизован"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function topUp(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'currency' => 'required|string|size:3',
            'payment_method_token' => 'nullable|string', // Делаем токен необязательным
        ]);

        /** @var User $user */
        $user = Auth::user();
        $organization = $user->currentOrganization;

        if (!$organization) {
            return response()->json(['message' => 'Organization not found for this user.'], 404); // Изменено сообщение для консистентности
        }

        $amountInCents = (int) ($request->input('amount') * 100);
        $currency = strtoupper($request->input('currency'));

        try {
            $returnUrl = route('api.v1.landing.billing.balance.show');

            // Для заглушки токен не критичен, но передаем, если есть
            $paymentMethodToken = $request->input('payment_method_token');

            $chargeResponse = $this->paymentGateway->createCharge(
                $user,
                $amountInCents,
                $currency,
                "Top-up organization balance for {$organization->name}",
                $paymentMethodToken, // Передаем токен, если он есть
                ['organization_id' => $organization->id, 'type' => 'balance_top_up'],
                $returnUrl
            );
            
            $payment = null;
            if ($chargeResponse->success && $chargeResponse->chargeId) {
                 // Создаем платеж сразу как УСПЕШНЫЙ для тестовых целей
                 $payment = Payment::create([
                    'user_id' => $user->id,
                    'payment_gateway_payment_id' => $chargeResponse->chargeId,
                    'amount' => $request->input('amount'),
                    'currency' => $currency,
                    'status' => Payment::STATUS_SUCCEEDED, // Сразу успешный
                    'description' => "Balance top-up successful for {$organization->name}",
                    'paid_at' => now(), // Проставляем время оплаты
                    'gateway_response' => $chargeResponse->gatewaySpecificResponse,
                ]);

                // Непосредственно пополняем баланс для тестовых целей
                // Сумма для balanceService должна быть в центах/копейках
                $this->balanceService->creditBalance(
                    $organization,
                    $amountInCents,
                    "Balance top-up via mock gateway: " . $chargeResponse->chargeId,
                    $payment // Связываем транзакцию с платежом
                );
                Log::info("Mock balance top-up successful and credited directly for org: {$organization->id}, amount: {$amountInCents}");
            }

            // Возвращаем изначальный ответ от шлюза, который должен быть успешным
            return response()->json($chargeResponse); 

        } catch (BalanceException $e) { // Это наше кастомное исключение для проблем с балансом
            Log::error('Balance top-up failed due to BalanceException: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) { // Любые другие неожиданные ошибки
            Log::error('Balance top-up initiation failed with general exception: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'An unexpected error occurred during balance top-up.'], 500);
        }
    }
} 