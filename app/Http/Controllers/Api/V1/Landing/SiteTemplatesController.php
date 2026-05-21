<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SiteTemplatesController extends Controller
{
    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРѕСЃС‚СѓРїРЅС‹Рµ С€Р°Р±Р»РѕРЅС‹
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $templates = SiteTemplate::where('is_active', true)
                ->orderBy('display_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'template_key', 'name', 'description', 'preview_image', 'is_premium', 'version']);

            $templatesData = $templates->map(function ($template) {
                return [
                    'id' => $template->id,
                    'template_key' => $template->template_key,
                    'name' => $template->name,
                    'description' => $template->description,
                    'preview_image' => $template->preview_image,
                    'is_premium' => $template->is_premium,
                    'version' => $template->version,
                    'theme_options' => $template->getAvailableThemeOptions(),
                ];
            });

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => $templatesData
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting site templates', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РћС€РёР±РєР° РїРѕР»СѓС‡РµРЅРёСЏ С€Р°Р±Р»РѕРЅРѕРІ'
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРµС‚Р°Р»Рё С€Р°Р±Р»РѕРЅР°
     */
    public function show(Request $request, string $templateKey): JsonResponse
    {
        try {
            $template = SiteTemplate::where('template_key', $templateKey)
                ->where('is_active', true)
                ->firstOrFail();

            $templateData = [
                'id' => $template->id,
                'template_key' => $template->template_key,
                'name' => $template->name,
                'description' => $template->description,
                'preview_image' => $template->preview_image,
                'is_premium' => $template->is_premium,
                'version' => $template->version,
                'default_blocks' => $template->getDefaultBlocksStructure(),
                'theme_options' => $template->getAvailableThemeOptions(),
                'layout_config' => $template->layout_config,
            ];

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => $templateData
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting site template', [
                'template_key' => $templateKey,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РЁР°Р±Р»РѕРЅ РЅРµ РЅР°Р№РґРµРЅ'
            ], 404);
        }
    }
}
