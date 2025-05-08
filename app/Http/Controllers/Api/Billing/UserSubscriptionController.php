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

        $subscription->loadMissing('plan');

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
        Log::info('[UserSubscriptionController::subscribe] Method entered.', [
            'request_data' => $request->all(),
            'user_id' => Auth::id(),
            'ip_address' => $request->ip()
        ]);

        try {
            Log::info('[UserSubscriptionController::subscribe] Attempting validation.');
            $request->validate([
                'plan_slug' => 'required|string|exists:subscription_plans,slug',
                'payment_method_token' => 'nullable|string',
            ]);
            Log::info('[UserSubscriptionController::subscribe] Validation passed.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('[UserSubscriptionController::subscribe] ValidationException caught.', [
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
                'trace' => $e->getTraceAsString()
            ]);
            // ValidationException обычно сама формирует корректный ответ 422, перевыбрасываем ее.
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[UserSubscriptionController::subscribe] Critical error during validation.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Critical error during request validation.'], 500);
        }

        $user = null;
        try {
            Log::info('[UserSubscriptionController::subscribe] Attempting to get authenticated user.');
            $user = Auth::user();
            if (!$user) {
                Log::warning('[UserSubscriptionController::subscribe] Auth::user() returned null.');
                return response()->json(['message' => 'User not authenticated.'], 401); // Или 500, если это неожиданно
            }
            Log::info('[UserSubscriptionController::subscribe] Authenticated user retrieved.', ['user_id' => $user->id]);
        } catch (\Throwable $e) {
            Log::error('[UserSubscriptionController::subscribe] Critical error retrieving authenticated user.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Critical error retrieving user.'], 500);
        }

        $plan = null;
        try {
            Log::info('[UserSubscriptionController::subscribe] Attempting to find plan by slug.', ['plan_slug' => $request->input('plan_slug')]);
            $plan = $this->planService->findBySlug($request->input('plan_slug'));
            if (!$plan) {
                Log::warning('[UserSubscriptionController::subscribe] Plan not found by slug.', ['plan_slug' => $request->input('plan_slug')]);
                return response()->json(['message' => 'Plan not found.'], 404);
            }
            Log::info('[UserSubscriptionController::subscribe] Plan found.', ['plan_id' => $plan->id]);
        } catch (\Throwable $e) {
            Log::error('[UserSubscriptionController::subscribe] Critical error finding plan.', [
                'plan_slug' => $request->input('plan_slug'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Critical error finding plan.'], 500);
        }

        try {
            Log::info('[UserSubscriptionController::subscribe] Attempting to subscribe user to plan.', ['user_id' => $user->id, 'plan_id' => $plan->id]);
            $subscription = $this->subscriptionService->subscribeUserToPlan(
                $user,
                $plan,
                $request->input('payment_method_token')
            );
            Log::info('[UserSubscriptionController::subscribe] Subscription successful.', ['subscription_id' => $subscription->id]);
            
            $subscription->loadMissing('plan');

            return new UserSubscriptionResource($subscription);
        } catch (SubscriptionException $e) {
            Log::warning('[UserSubscriptionController::subscribe] SubscriptionException caught.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Логируем и для SubscriptionException
            ]);
            return response()->json(['message' => $e->getMessage()], 400);
        } catch (\Throwable $e) { // Изменено с \Exception на \Throwable
            Log::error('[UserSubscriptionController::subscribe] Generic subscription error (main try-catch).', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
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
            Log::error('Generic cancellation error: ' . $e->getMessage() . ' Stack trace: ' . $e->getTraceAsString());
            return response()->json(['message' => 'An unexpected error occurred while canceling the subscription.'], 500);
        }
    }
    
    // TODO: Добавить метод для смены плана (switchPlan)
    // TODO: Добавить метод для возобновления подписки (resumeSubscription), если это необходимо
} 