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
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $service = new OrganizationSubscriptionService();
        $subscription = $service->getCurrentSubscription($organization->id);
        return response()->json($subscription);
    }

    public function subscribe(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $planSlug = $request->input('plan_slug');
        $isAutoPaymentEnabled = $request->boolean('is_auto_payment_enabled', true);
        $service = new OrganizationSubscriptionService();
        $subscription = $service->subscribe($organization->id, $planSlug, $isAutoPaymentEnabled);
        return response()->json($subscription);
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $planSlug = $request->input('plan_slug');
        $isAutoPaymentEnabled = $request->boolean('is_auto_payment_enabled', true);
        $service = new OrganizationSubscriptionService();
        $subscription = $service->updateSubscription($organization->id, $planSlug, $isAutoPaymentEnabled);
        return response()->json($subscription);
    }

    public function updateAutoPayment(Request $request)
    {
        $request->validate([
            'is_auto_payment_enabled' => 'required|boolean',
        ]);
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $subscription = (new \App\Repositories\Landing\OrganizationSubscriptionRepository())->getByOrganizationId($organization->id);
        if (!$subscription) {
            return response()->json(['error' => 'Подписка не найдена'], 404);
        }
        $subscription->update(['is_auto_payment_enabled' => $request->boolean('is_auto_payment_enabled')]);
        return response()->json($subscription);
    }
} 