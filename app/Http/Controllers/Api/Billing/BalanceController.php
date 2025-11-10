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

    // МЕТОД topUp УДАЛЕН - Mock платежи вырезаны
    // При подключении реального платежного шлюза (ЮKassa, CloudPayments) создать новый метод
} 