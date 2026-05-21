<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Interfaces\Billing\BalanceServiceInterface;
use App\Interfaces\Billing\PaymentGatewayInterface;
use App\Http\Resources\Billing\OrganizationBalanceResource; // РЎРѕР·РґР°РґРёРј
use App\Http\Resources\Billing\BalanceTransactionResource; // РЎРѕР·РґР°РґРёРј
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // Р”Р»СЏ С‚Р°Р№РїС…РёРЅС‚Р°
use App\Models\Payment; // <-- РџСЂР°РІРёР»СЊРЅС‹Р№ РёРјРїРѕСЂС‚
use App\Exceptions\Billing\BalanceException;
use Illuminate\Support\Facades\Log; // <-- РџСЂР°РІРёР»СЊРЅС‹Р№ РёРјРїРѕСЂС‚

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
     *     summary="РџРѕР»СѓС‡РёС‚СЊ С‚РµРєСѓС‰РёР№ Р±Р°Р»Р°РЅСЃ РѕСЂРіР°РЅРёР·Р°С†РёРё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="РўРµРєСѓС‰РёР№ Р±Р°Р»Р°РЅСЃ РѕСЂРіР°РЅРёР·Р°С†РёРё",
     *         @OA\JsonContent(ref="#/components/schemas/OrganizationBalanceResource")
     *     ),
     *     @OA\Response(response=401, description="РќРµ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ"),
     *     @OA\Response(response=404, description="РћСЂРіР°РЅРёР·Р°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР° РёР»Рё РЅРµ РїСЂРёРІСЏР·Р°РЅР° Рє РїРѕР»СЊР·РѕРІР°С‚РµР»СЋ")
     * )
     */
    public function show(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return \App\Http\Responses\AdminResponse::fromPayload(['message' => 'Organization not found for this user.'], 404);
        }

        // РљРµС€РёСЂСѓРµРј Р±Р°Р»Р°РЅСЃ РЅР° 30 СЃРµРєСѓРЅРґ РґР»СЏ РёР·Р±РµР¶Р°РЅРёСЏ РїРѕРІС‚РѕСЂРЅС‹С… Р·Р°РїСЂРѕСЃРѕРІ
        $balance = \Illuminate\Support\Facades\Cache::remember(
            "organization_balance_{$organizationId}", 
            30, 
            function () use ($organizationId) {
                $organization = \App\Models\Organization::find($organizationId);
                return $organization ? $this->balanceService->getOrCreateOrganizationBalance($organization) : null;
            }
        );
        
        if (!$balance) {
            return \App\Http\Responses\AdminResponse::fromPayload(['message' => 'Organization not found.'], 404);
        }

        return new OrganizationBalanceResource($balance);
    }

    /**
     * @OA\Get(
     *     path="/api/billing/balance/transactions",
     *     summary="РџРѕР»СѓС‡РёС‚СЊ РёСЃС‚РѕСЂРёСЋ С‚СЂР°РЅР·Р°РєС†РёР№ РїРѕ Р±Р°Р»Р°РЅСЃСѓ РѕСЂРіР°РЅРёР·Р°С†РёРё",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *      @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="РљРѕР»РёС‡РµСЃС‚РІРѕ Р·Р°РїРёСЃРµР№ РЅР° СЃС‚СЂР°РЅРёС†Сѓ",
     *         required=false,
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="РЎРїРёСЃРѕРє С‚СЂР°РЅР·Р°РєС†РёР№",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/BalanceTransactionResource"))
     *     ),
     *     @OA\Response(response=401, description="РќРµ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ"),
     *     @OA\Response(response=404, description="РћСЂРіР°РЅРёР·Р°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР°")
     * )
     */
    public function getTransactions(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $organization = $user->currentOrganization;

        if (!$organization) {
            return \App\Http\Responses\AdminResponse::fromPayload(['message' => 'Organization not found.'], 404);
        }

        $balance = $this->balanceService->getOrCreateOrganizationBalance($organization);
        $transactions = $balance->transactions()->latest()->paginate($request->input('limit', 15));

        return BalanceTransactionResource::collection($transactions);
    }

    // РњР•РўРћР” topUp РЈР”РђР›Р•Рќ - Mock РїР»Р°С‚РµР¶Рё РІС‹СЂРµР·Р°РЅС‹
    // РџСЂРё РїРѕРґРєР»СЋС‡РµРЅРёРё СЂРµР°Р»СЊРЅРѕРіРѕ РїР»Р°С‚РµР¶РЅРѕРіРѕ С€Р»СЋР·Р° (Р®Kassa, CloudPayments) СЃРѕР·РґР°С‚СЊ РЅРѕРІС‹Р№ РјРµС‚РѕРґ
} 