<?php

namespace App\Http\Controllers\Api\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\OrganizationSubscriptionService;

class OrganizationSubscriptionController extends Controller
{
    public function show(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $service = app(OrganizationSubscriptionService::class);
        $subscription = $service->getCurrentSubscription($organizationId);
        
        if (!$subscription) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_subscription' => false,
                    'subscription' => null,
                    'message' => 'У организации нет активной подписки'
                ]
            ]);
        }
        
        $subscription->load(['plan', 'activeBundledModules.module']);
        
        $plan = $subscription->plan;
        $bundledModules = $subscription->activeBundledModules->map(function ($activation) {
            return [
                'id' => $activation->module->id,
                'name' => $activation->module->name,
                'slug' => $activation->module->slug,
                'category' => $activation->module->category,
                'icon' => $activation->module->icon,
                'activated_at' => $activation->activated_at?->format('Y-m-d H:i:s'),
                'expires_at' => $activation->expires_at?->format('Y-m-d H:i:s'),
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'has_subscription' => true,
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'plan' => [
                        'slug' => $plan->slug ?? null,
                        'name' => $plan->name ?? 'Unknown',
                        'description' => $plan->description ?? '',
                        'price' => $plan->price ?? 0,
                        'currency' => $plan->currency ?? 'RUB',
                        'features' => $plan->features ?? [],
                    ],
                    'is_trial' => false,
                    'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d H:i:s'),
                    'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                    'next_billing_at' => $subscription->next_billing_at?->format('Y-m-d H:i:s'),
                    'is_canceled' => $subscription->canceled_at !== null,
                    'canceled_at' => $subscription->canceled_at?->format('Y-m-d H:i:s'),
                    'is_auto_payment_enabled' => $subscription->is_auto_payment_enabled,
                    'bundled_modules' => $bundledModules,
                    'bundled_modules_count' => $bundledModules->count(),
                ]
            ]
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_slug' => 'required|string',
            'duration_days' => 'nullable|integer|in:30,90,365',
            'is_auto_payment_enabled' => 'nullable|boolean'
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $planSlug = $request->input('plan_slug');
        $durationDays = $request->input('duration_days', 30);
        $isAutoPaymentEnabled = $request->boolean('is_auto_payment_enabled', true);
        $service = app(OrganizationSubscriptionService::class);
        $subscription = $service->subscribe($organizationId, $planSlug, $isAutoPaymentEnabled, $durationDays);
        
        return response()->json([
            'success' => true,
            'data' => $subscription
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $planSlug = $request->input('plan_slug');
        $isAutoPaymentEnabled = $request->boolean('is_auto_payment_enabled', true);
        $service = app(OrganizationSubscriptionService::class);
        $subscription = $service->updateSubscription($organizationId, $planSlug, $isAutoPaymentEnabled);
        
        return response()->json([
            'success' => true,
            'data' => $subscription
        ]);
    }

    public function cancel(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $service = app(OrganizationSubscriptionService::class);
        $result = $service->cancelSubscription($organizationId);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], $result['status_code'] ?? 400);
        }
        
        return response()->json([
            'success' => true,
            'data' => $result['subscription'],
            'message' => 'Подписка отменена. Доступ сохранится до ' . $result['subscription']->ends_at->format('d.m.Y')
        ]);
    }

    public function changePlanPreview(Request $request)
    {
        $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $service = app(OrganizationSubscriptionService::class);
        $result = $service->previewPlanChange($organizationId, $request->input('plan_slug'));
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], $result['status_code'] ?? 400);
        }
        
        return response()->json([
            'success' => true,
            'data' => $result['preview'],
            'message' => $result['message']
        ]);
    }

    public function changePlan(Request $request)
    {
        $request->validate([
            'plan_slug' => 'required|string|exists:subscription_plans,slug',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $service = app(OrganizationSubscriptionService::class);
        $result = $service->changePlan($organizationId, $request->input('plan_slug'));
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], $result['status_code'] ?? 400);
        }
        
        return response()->json([
            'success' => true,
            'data' => $result['subscription'],
            'billing_info' => $result['billing_info'] ?? null,
            'message' => $result['message']
        ]);
    }

    public function updateAutoPayment(Request $request)
    {
        $request->validate([
            'is_auto_payment_enabled' => 'required|boolean',
        ]);
        
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId || $organizationId != $user->current_organization_id) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        
        $subscription = (new \App\Repositories\Landing\OrganizationSubscriptionRepository())->getByOrganizationId($organizationId);
        if (!$subscription) {
            return response()->json(['error' => 'Подписка не найдена'], 404);
        }
        
        $subscription->update(['is_auto_payment_enabled' => $request->boolean('is_auto_payment_enabled')]);
        
        return response()->json([
            'success' => true,
            'data' => $subscription
        ]);
    }
}