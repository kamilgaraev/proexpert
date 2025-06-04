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
        $organization = Auth::user()->organization;
        $service = new OrganizationSubscriptionService();
        $subscription = $service->getCurrentSubscription($organization->id);
        return response()->json($subscription);
    }

    public function subscribe(Request $request)
    {
        $organization = Auth::user()->organization;
        $planSlug = $request->input('plan_slug');
        $service = new OrganizationSubscriptionService();
        $subscription = $service->subscribe($organization->id, $planSlug);
        return response()->json($subscription);
    }

    public function update(Request $request)
    {
        $organization = Auth::user()->organization;
        $planSlug = $request->input('plan_slug');
        $service = new OrganizationSubscriptionService();
        $subscription = $service->updateSubscription($organization->id, $planSlug);
        return response()->json($subscription);
    }
} 