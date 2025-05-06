<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Interfaces\Billing\SubscriptionPlanServiceInterface;
use App\Http\Resources\Billing\SubscriptionPlanResource; // Мы создадим этот ресурс позже
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    protected SubscriptionPlanServiceInterface $planService;

    public function __construct(SubscriptionPlanServiceInterface $planService)
    {
        $this->planService = $planService;
    }

    /**
     * @OA\Get(
     *     path="/api/billing/plans",
     *     summary="Получить список активных тарифных планов",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Список тарифных планов",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/SubscriptionPlanResource"))
     *     ),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function index(Request $request)
    {
        $plans = $this->planService->getActivePlans();
        return SubscriptionPlanResource::collection($plans);
    }
} 