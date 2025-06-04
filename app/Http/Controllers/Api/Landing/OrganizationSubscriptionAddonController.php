<?php

namespace App\Http\Controllers\Api\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\OrganizationSubscriptionAddonService;

class OrganizationSubscriptionAddonController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $service = new OrganizationSubscriptionAddonService();
        $allAddons = $service->getAllAddons();
        $orgAddons = $service->getOrganizationAddons($organization->id);
        return response()->json([
            'all' => $allAddons,
            'connected' => $orgAddons
        ]);
    }

    public function attach(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $addonId = $request->input('addon_id');
        $service = new OrganizationSubscriptionAddonService();
        $result = $service->attachAddon($organization->id, $addonId);
        return response()->json($result);
    }

    public function detach($id)
    {
        $user = Auth::user();
        $organizationId = request()->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $service = new OrganizationSubscriptionAddonService();
        $result = $service->detachAddon($organization->id, $id);
        return response()->json(['success' => (bool)$result]);
    }
} 