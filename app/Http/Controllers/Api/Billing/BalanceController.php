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
        $organization = $user->currentOrganization; // Предполагаем связь user->organization

        if (!$organization) {
            return response()->json(['message' => 'Organization not found for this user.'], 404);
        }

        $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
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
            'amount' => 'required|numeric|min:1', // Минимальная сумма для пополнения
            'currency' => 'required|string|size:3',
            'payment_method_token' => 'required|string', // Для mock-шлюза делаем обязательным
        ]);

        /** @var User $user */
        $user = Auth::user();
        $organization = $user->currentOrganization;

        if (!$organization) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $amountInCents = (int) ($request->input('amount') * 100);
        $currency = strtoupper($request->input('currency'));

        try {
            // При реальной интеграции здесь может быть URL для возврата после успешной/неуспешной оплаты
            $returnUrl = route('landing.billing.balance.show'); // Пример

            $chargeResponse = $this->paymentGateway->createCharge(
                $user,
                $amountInCents,
                $currency,
                "Top-up organization balance for {$organization->name}",
                $request->input('payment_method_token'),
                ['organization_id' => $organization->id, 'type' => 'balance_top_up'],
                $returnUrl
            );
            
            if ($chargeResponse->success && $chargeResponse->chargeId) {
                 Payment::create([
                    'user_id' => $user->id,
                    // 'organization_id' => $organization->id, // Поле organization_id не существует в Payment модели по умолчанию.
                                                              // Если оно необходимо, его нужно добавить в миграцию и модель Payment.
                    'payment_gateway_payment_id' => $chargeResponse->chargeId,
                    'amount' => $request->input('amount'),
                    'currency' => $currency,
                    'status' => Payment::STATUS_PENDING, 
                    'description' => "Balance top-up initiated for {$organization->name}",
                    'gateway_response' => $chargeResponse->gatewaySpecificResponse,
                ]);
            }

            return response()->json($chargeResponse); 

        } catch (BalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Balance top-up initiation failed: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'An unexpected error occurred during balance top-up.'], 500);
        }
    }
} 