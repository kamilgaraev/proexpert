<?php

namespace App\Http\Controllers\Api\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\OrganizationOneTimePurchaseService;

class OrganizationOneTimePurchaseController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $type = $request->input('type');
        $description = $request->input('description');
        $amount = $request->input('amount');
        $currency = $request->input('currency', 'RUB');
        $service = new OrganizationOneTimePurchaseService();
        $purchase = $service->create($organization->id, $user->id, $type, $description, $amount, $currency);
        return response()->json($purchase);
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        $organization = $user->organizations()->where('organization_id', $organizationId)->first();
        if (!$organization) {
            return response()->json(['error' => 'Организация не найдена или нет доступа'], 404);
        }
        $service = new OrganizationOneTimePurchaseService();
        $history = $service->getHistory($organization->id);
        return response()->json($history);
    }
} 