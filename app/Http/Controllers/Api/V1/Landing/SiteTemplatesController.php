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
     * Получить доступные шаблоны
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

            return response()->json([
                'success' => true,
                'data' => $templatesData
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting site templates', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения шаблонов'
            ], 500);
        }
    }

    /**
     * Получить детали шаблона
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

            return response()->json([
                'success' => true,
                'data' => $templateData
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting site template', [
                'template_key' => $templateKey,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Шаблон не найден'
            ], 404);
        }
    }
}
