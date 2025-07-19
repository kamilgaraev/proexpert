<?php

namespace App\Http\Controllers\Api\V1\Landing\Blog;

use App\Http\Controllers\Controller;
use App\Services\Blog\BlogSeoService;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BlogSeoController extends Controller
{
    public function __construct(
        private BlogSeoService $seoService
    ) {}

    public function getSettings()
    {
        try {
            $settings = $this->seoService->getSettings();
            
            return new SuccessResponse($settings->toArray(), 'SEO настройки');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении SEO настроек: ' . $e->getMessage(), 500);
        }
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'site_name' => 'sometimes|string|max:255',
            'site_description' => 'sometimes|nullable|string|max:500',
            'site_keywords' => 'sometimes|nullable|array',
            'default_og_image' => 'sometimes|nullable|string',
            'auto_generate_meta_description' => 'sometimes|boolean',
            'meta_description_length' => 'sometimes|integer|min:50|max:300',
            'enable_breadcrumbs' => 'sometimes|boolean',
            'enable_structured_data' => 'sometimes|boolean',
            'enable_sitemap' => 'sometimes|boolean',
            'enable_rss' => 'sometimes|boolean',
            'robots_txt' => 'sometimes|nullable|string',
            'social_media_links' => 'sometimes|nullable|array',
            'google_analytics_id' => 'sometimes|nullable|string',
            'yandex_metrica_id' => 'sometimes|nullable|string',
            'google_search_console_verification' => 'sometimes|nullable|string',
            'yandex_webmaster_verification' => 'sometimes|nullable|string',
        ]);

        try {
            $settings = $this->seoService->updateSettings($request->validated());
            
            return new SuccessResponse($settings->toArray(), 'SEO настройки обновлены');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при обновлении SEO настроек: ' . $e->getMessage(), 500);
        }
    }

    public function generateSitemap()
    {
        try {
            $sitemap = $this->seoService->generateSitemap();
            
            return response($sitemap, 200)
                ->header('Content-Type', 'application/xml');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации sitemap: ' . $e->getMessage(), 500);
        }
    }

    public function generateRssFeed()
    {
        try {
            $rss = $this->seoService->generateRssFeed();
            
            return response($rss, 200)
                ->header('Content-Type', 'application/rss+xml');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации RSS: ' . $e->getMessage(), 500);
        }
    }

    public function generateRobotsTxt()
    {
        try {
            $robots = $this->seoService->generateRobotsTxt();
            
            return response($robots, 200)
                ->header('Content-Type', 'text/plain');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации robots.txt: ' . $e->getMessage(), 500);
        }
    }

    public function previewSitemap()
    {
        try {
            $sitemap = $this->seoService->generateSitemap();
            
            return new SuccessResponse(['sitemap' => $sitemap], 'Предпросмотр sitemap');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации sitemap: ' . $e->getMessage(), 500);
        }
    }

    public function previewRssFeed()
    {
        try {
            $rss = $this->seoService->generateRssFeed();
            
            return new SuccessResponse(['rss' => $rss], 'Предпросмотр RSS');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации RSS: ' . $e->getMessage(), 500);
        }
    }

    public function previewRobotsTxt()
    {
        try {
            $robots = $this->seoService->generateRobotsTxt();
            
            return new SuccessResponse(['robots' => $robots], 'Предпросмотр robots.txt');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при генерации robots.txt: ' . $e->getMessage(), 500);
        }
    }
} 