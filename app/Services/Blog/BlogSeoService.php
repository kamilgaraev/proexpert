<?php

namespace App\Services\Blog;

use App\Models\Blog\BlogSeoSettings;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Repositories\Blog\BlogArticleRepository;
use App\Repositories\Blog\BlogCategoryRepository;
use Illuminate\Support\Facades\Cache;

class BlogSeoService
{
    public function __construct(
        private BlogArticleRepository $articleRepository,
        private BlogCategoryRepository $categoryRepository
    ) {}

    public function getSettings(): BlogSeoSettings
    {
        return BlogSeoSettings::getInstance();
    }

    public function updateSettings(array $data): BlogSeoSettings
    {
        $settings = $this->getSettings();
        $settings->update($data);
        
        Cache::forget('blog_seo_settings');
        
        return $settings;
    }

    public function generateSitemap(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        $xml .= $this->addSitemapUrl(url('/blog'), now()->toISOString(), 'daily', '1.0');

        $categories = $this->categoryRepository->getActiveCategories();
        foreach ($categories as $category) {
            $xml .= $this->addSitemapUrl(
                url("/blog/category/{$category->slug}"),
                $category->updated_at->toISOString(),
                'weekly',
                '0.8'
            );
        }

        $articles = BlogArticle::published()->orderBy('published_at', 'desc')->get();
        foreach ($articles as $article) {
            $xml .= $this->addSitemapUrl(
                url($article->url),
                $article->updated_at->toISOString(),
                'monthly',
                '0.6'
            );
        }

        $xml .= '</urlset>';

        return $xml;
    }

    public function generateRssFeed(): string
    {
        $settings = $this->getSettings();
        $articles = BlogArticle::published()
            ->where('is_published_in_rss', true)
            ->orderBy('published_at', 'desc')
            ->limit(20)
            ->get();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . htmlspecialchars($settings->site_name) . '</title>' . "\n";
        $xml .= '<link>' . url('/blog') . '</link>' . "\n";
        $xml .= '<description>' . htmlspecialchars($settings->site_description ?: 'Блог') . '</description>' . "\n";
        $xml .= '<language>ru-RU</language>' . "\n";
        $xml .= '<lastBuildDate>' . now()->toRSSString() . '</lastBuildDate>' . "\n";

        foreach ($articles as $article) {
            $xml .= '<item>' . "\n";
            $xml .= '<title>' . htmlspecialchars($article->title) . '</title>' . "\n";
            $xml .= '<link>' . url($article->url) . '</link>' . "\n";
            $xml .= '<description>' . htmlspecialchars($article->excerpt ?: strip_tags(\Illuminate\Support\Str::limit($article->content, 200))) . '</description>' . "\n";
            $xml .= '<content:encoded><![CDATA[' . $article->content . ']]></content:encoded>' . "\n";
            $xml .= '<pubDate>' . $article->published_at->toRSSString() . '</pubDate>' . "\n";
            $xml .= '<guid>' . url($article->url) . '</guid>' . "\n";
            $xml .= '<category>' . htmlspecialchars($article->category->name) . '</category>' . "\n";
            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        return $xml;
    }

    public function generateStructuredData(BlogArticle $article): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $article->title,
            'description' => $article->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($article->content), 160),
            'image' => $article->featured_image ? url($article->featured_image) : null,
            'author' => [
                '@type' => 'Person',
                'name' => $article->author->name,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
            'datePublished' => $article->published_at?->toISOString(),
            'dateModified' => $article->updated_at->toISOString(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => url($article->url),
            ],
            'articleSection' => $article->category->name,
            'keywords' => $article->tags->pluck('name')->implode(', '),
        ];
    }

    public function generateBreadcrumbs(BlogArticle $article): array
    {
        return [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Главная',
                'item' => url('/'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => 'Блог',
                'item' => url('/blog'),
            ],
            [
                '@type' => 'ListItem',
                'position' => 3,
                'name' => $article->category->name,
                'item' => url("/blog/category/{$article->category->slug}"),
            ],
            [
                '@type' => 'ListItem',
                'position' => 4,
                'name' => $article->title,
                'item' => url($article->url),
            ],
        ];
    }

    public function generateRobotsTxt(): string
    {
        $settings = $this->getSettings();
        
        if ($settings->robots_txt) {
            return $settings->robots_txt;
        }

        $robots = "User-agent: *\n";
        $robots .= "Allow: /\n";
        $robots .= "Disallow: /admin/\n";
        $robots .= "Disallow: /api/\n";
        $robots .= "\n";
        $robots .= "Sitemap: " . url('/blog/sitemap.xml') . "\n";

        return $robots;
    }

    private function addSitemapUrl(string $url, string $lastmod, string $changefreq, string $priority): string
    {
        return "<url>\n" .
               "<loc>{$url}</loc>\n" .
               "<lastmod>{$lastmod}</lastmod>\n" .
               "<changefreq>{$changefreq}</changefreq>\n" .
               "<priority>{$priority}</priority>\n" .
               "</url>\n";
    }
} 