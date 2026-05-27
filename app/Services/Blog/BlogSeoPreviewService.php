<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Models\Blog\BlogArticle;
use Illuminate\Support\Arr;

final class BlogSeoPreviewService
{
    private const TITLE_MIN_LENGTH = 30;
    private const TITLE_MAX_LENGTH = 70;
    private const DESCRIPTION_MIN_LENGTH = 80;
    private const DESCRIPTION_MAX_LENGTH = 160;

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     url: string,
     *     og_title: string,
     *     og_description: string,
     *     og_image: string,
     *     checks: array<int, array{key: string, status: string, message: string}>
     * }
     */
    public function preview(BlogArticle|array $source): array
    {
        $data = $source instanceof BlogArticle ? $this->articleData($source) : $source;

        $title = $this->firstFilled($data, ['meta_title', 'title']);
        $description = $this->firstFilled($data, ['meta_description', 'excerpt']);
        $url = $this->resolveUrl($data);
        $ogTitle = $this->firstFilled($data, ['og_title', 'meta_title', 'title']);
        $ogDescription = $this->firstFilled($data, ['og_description', 'meta_description', 'excerpt']);
        $ogImage = $this->firstFilled($data, ['og_image', 'featured_image']);
        $noindex = (bool) Arr::get($data, 'noindex', false);

        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'og_image' => $ogImage,
            'checks' => [
                $this->lengthCheck(
                    'title_length',
                    mb_strlen($title),
                    self::TITLE_MIN_LENGTH,
                    self::TITLE_MAX_LENGTH,
                    'blog_cms.seo_preview_checks.title',
                ),
                $this->lengthCheck(
                    'description_length',
                    mb_strlen($description),
                    self::DESCRIPTION_MIN_LENGTH,
                    self::DESCRIPTION_MAX_LENGTH,
                    'blog_cms.seo_preview_checks.description',
                ),
                [
                    'key' => 'canonical_url',
                    'status' => $url !== '' ? 'ok' : 'warning',
                    'message' => $url !== ''
                        ? trans_message('blog_cms.seo_preview_checks.canonical_ok')
                        : trans_message('blog_cms.seo_preview_checks.canonical_missing'),
                ],
                [
                    'key' => 'og_image',
                    'status' => $ogImage !== '' ? 'ok' : 'warning',
                    'message' => $ogImage !== ''
                        ? trans_message('blog_cms.seo_preview_checks.og_image_ok')
                        : trans_message('blog_cms.seo_preview_checks.og_image_missing'),
                ],
                [
                    'key' => 'noindex',
                    'status' => $noindex ? 'warning' : 'ok',
                    'message' => $noindex
                        ? trans_message('blog_cms.seo_preview_checks.noindex_warning')
                        : trans_message('blog_cms.seo_preview_checks.noindex_ok'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleData(BlogArticle $article): array
    {
        return [
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => $article->excerpt,
            'canonical_url' => $article->canonical_url,
            'featured_image' => $article->featured_image,
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'og_title' => $article->og_title,
            'og_description' => $article->og_description,
            'og_image' => $article->og_image,
            'noindex' => $article->noindex,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private function firstFilled(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) Arr::get($data, $key, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveUrl(array $data): string
    {
        $canonicalUrl = trim((string) Arr::get($data, 'canonical_url', ''));

        if ($canonicalUrl !== '') {
            return $canonicalUrl;
        }

        $baseUrl = rtrim((string) config('blog.marketing_frontend_url'), '/');
        $slug = trim((string) Arr::get($data, 'slug', ''));

        if ($baseUrl === '') {
            return $slug;
        }

        return $slug !== '' ? "{$baseUrl}/blog/{$slug}" : "{$baseUrl}/blog";
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private function lengthCheck(string $key, int $length, int $min, int $max, string $translationPrefix): array
    {
        if ($length === 0) {
            return [
                'key' => $key,
                'status' => 'danger',
                'message' => trans_message("{$translationPrefix}_missing"),
            ];
        }

        if ($length < $min) {
            return [
                'key' => $key,
                'status' => 'warning',
                'message' => trans_message("{$translationPrefix}_short", ['count' => $length]),
            ];
        }

        if ($length > $max) {
            return [
                'key' => $key,
                'status' => 'warning',
                'message' => trans_message("{$translationPrefix}_long", ['count' => $length]),
            ];
        }

        return [
            'key' => $key,
            'status' => 'ok',
            'message' => trans_message("{$translationPrefix}_ok", ['count' => $length]),
        ];
    }
}
