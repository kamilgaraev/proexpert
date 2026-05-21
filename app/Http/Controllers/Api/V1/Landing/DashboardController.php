<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\DashboardService;

class DashboardController extends Controller
{
    private DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Р“Р»Р°РІРЅР°СЏ СЃРІРѕРґРєР° РґР°С€Р±РѕСЂРґР° РґР»СЏ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.
     * Р’РѕР·РІСЂР°С‰Р°РµС‚ СЂР°СЃС€РёСЂРµРЅРЅС‹Р№ РЅР°Р±РѕСЂ РјРµС‚СЂРёРє, РІРєР»СЋС‡Р°СЏ РґРµС‚Р°Р»СЊРЅС‹Р№ СЃРїРёСЃРѕРє РєРѕРјР°РЅРґС‹.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        // РљРµС€РёСЂСѓРµРј РґР°РЅРЅС‹Рµ РґР°С€Р±РѕСЂРґР° РЅР° 2 РјРёРЅСѓС‚С‹
        $cacheKey = "dashboard_data_{$organizationId}";
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use ($organizationId) {
            return $this->dashboardService->getDashboardData($organizationId);
        });

        return \App\Http\Responses\LandingResponse::fromPayload($data);
    }
} 