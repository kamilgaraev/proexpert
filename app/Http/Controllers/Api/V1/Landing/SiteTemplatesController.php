<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteTemplate;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use function trans_message;

class SiteTemplatesController extends Controller
{
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

            return LandingResponse::success($templatesData, trans_message('landing.site_templates.loaded'));
        } catch (\Throwable $e) {
            Log::error('Error getting site templates', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return LandingResponse::error(trans_message('landing.site_templates.load_error'), 500);
        }
    }

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

            return LandingResponse::success($templateData, trans_message('landing.site_templates.details_loaded'));
        } catch (\Throwable $e) {
            Log::error('Error getting site template', [
                'template_key' => $templateKey,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return LandingResponse::error(trans_message('landing.site_templates.not_found'), 404);
        }
    }
}
