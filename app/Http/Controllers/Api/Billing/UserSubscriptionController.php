<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Interfaces\Billing\UserSubscriptionServiceInterface;
use App\Interfaces\Billing\SubscriptionPlanServiceInterface;
use App\Http\Resources\Billing\UserSubscriptionResource; // Создадим позже
use Illuminate\Http\Request;
use App\Models\UserSubscription; // Для Route Model Binding, если понадобится
use App\Exceptions\Billing\SubscriptionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserSubscriptionController extends Controller
{
    protected UserSubscriptionServiceInterface $subscriptionService;
    protected SubscriptionPlanServiceInterface $planService;

    public function __construct(
        UserSubscriptionServiceInterface $subscriptionService,
        SubscriptionPlanServiceInterface $planService
    ) {
        $this->subscriptionService = $subscriptionService;
        $this->planService = $planService;
    }

    /**
     * @OA\Get(
     *     path="/api/billing/subscription",
     *     summary="Получить текущую подписку пользователя",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Текущая подписка",
     *         @OA\JsonContent(ref="#/components/schemas/UserSubscriptionResource")
     *     ),
     *     @OA\Response(response=404, description="Подписка не найдена"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function show(Request $request)
    {
        $user = Auth::user();
        $subscription = $this->subscriptionService->getUserCurrentValidSubscription($user);

        if (!$subscription) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }
        return new UserSubscriptionResource($subscription);
    }

    /**
     * @OA\Post(
     *     path="/api/billing/subscribe",
     *     summary="Подписать пользователя на тарифный план",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"plan_slug"},
     *             @OA\Property(property="plan_slug", type="string", example="start", description="Slug тарифного плана"),
     *             @OA\Property(property="payment_method_token", type="string", nullable=true, example="tok_mock_visa", description="Токен метода оплаты от платежного шлюза (если требуется немедленная оплата)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Подписка успешно создана/обновлена",
     *         @OA\JsonContent(ref="#/components/schemas/UserSubscriptionResource")
     *     ),
     *     @OA\Response(response=400, description="Ошибка при подписке (например, неверный план, ошибка платежа)"),
     *     @OA\Response(response=401, description="Не авторизован"),
     *     @OA\Response(response=422, description="Ошибка валидации")
     * )
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
            'payment_method_token' => 'nullable|string',
        ]);

        $user = Auth::user();
        $plan = $this->planService->findBySlug($request->input('plan_slug'));

        if (!$plan) {
            return response()->json(['message' => 'Plan not found.'], 404);
        }

        try {
            $subscription = $this->subscriptionService->subscribeUserToPlan(
                $user,
                $plan,
                $request->input('payment_method_token')
            );
            return new UserSubscriptionResource($subscription);
        } catch (SubscriptionException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Generic subscription error: ' . $e->getMessage() . ' Stack trace: ' . $e->getTraceAsString());
            return response()->json(['message' => 'An unexpected error occurred while subscribing.'], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/billing/subscription/cancel",
     *     summary="Отменить текущую подписку пользователя",
     *     tags={"Billing"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="at_period_end", type="boolean", example=true, description="Отменить в конце оплаченного периода (true) или немедленно (false, если поддерживается)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Подписка успешно отменена",
     *         @OA\JsonContent(ref="#/components/schemas/UserSubscriptionResource")
     *     ),
     *     @OA\Response(response=404, description="Активная подписка не найдена"),
     *     @OA\Response(response=400, description="Ошибка при отмене подписки"),
     *     @OA\Response(response=401, description="Не авторизован")
     * )
     */
    public function cancel(Request $request)
    {
        $user = Auth::user();
        $currentSubscription = $this->subscriptionService->getUserCurrentValidSubscription($user);

        if (!$currentSubscription) {
            return response()->json(['message' => 'No active subscription to cancel.'], 404);
        }

        $atPeriodEnd = $request->input('at_period_end', true);

        try {
            $canceledSubscription = $this->subscriptionService->cancelSubscription($currentSubscription, $atPeriodEnd);
            return new UserSubscriptionResource($canceledSubscription);
        } catch (SubscriptionException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            // Log::error('Generic cancellation error: ' . $e->getMessage());
            return response()->json(['message' => 'An unexpected error occurred while canceling the subscription.'], 500);
        }
    }
    
    // TODO: Добавить метод для смены плана (switchPlan)
    // TODO: Добавить метод для возобновления подписки (resumeSubscription), если это необходимо
} 