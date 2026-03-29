<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogMediaAsset;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use RuntimeException;

class BlogMediaService
{
    public function __construct(
        private readonly FileService $fileService,
    ) {
    }

    public function uploadMarketingAsset(UploadedFile $file, SystemAdmin $systemAdmin, array $meta = []): BlogMediaAsset
    {
        $organization = $this->resolveContentOrganization();
        $storagePath = $this->fileService->upload(
            $file,
            'cms/blog/media',
            null,
            'public',
            $organization,
            true,
        );

        if ($storagePath === false) {
            throw new RuntimeException('Blog media upload failed.');
        }

        $publicUrl = $this->fileService->publicUrl($storagePath, $organization)
            ?? $this->fileService->url($storagePath, $organization);

        if (!is_string($publicUrl) || $publicUrl === '') {
            throw new RuntimeException('Blog media public URL generation failed.');
        }

        [$width, $height] = $this->resolveImageDimensions($file);

        return BlogMediaAsset::query()->create([
            'blog_context' => BlogContextEnum::MARKETING,
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'public_url' => $publicUrl,
            'mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => Arr::get($meta, 'alt_text'),
            'caption' => Arr::get($meta, 'caption'),
            'focal_point' => Arr::get($meta, 'focal_point'),
            'usage_metadata' => [],
            'uploaded_by_system_admin_id' => $systemAdmin->id,
        ]);
    }

    public function refreshUsageMetadata(BlogMediaAsset $asset): array
    {
        $usage = $this->getUsageMap($asset);

        $asset->forceFill([
            'usage_metadata' => [
                'count' => count($usage),
                'items' => $usage,
            ],
        ])->save();

        return $usage;
    }

    public function deleteAsset(BlogMediaAsset $asset): void
    {
        $usage = $this->refreshUsageMetadata($asset);

        if ($usage !== []) {
            throw new RuntimeException('Blog media asset is currently used.');
        }

        $this->fileService->delete($asset->storage_path, $this->resolveContentOrganization());
        $asset->delete();
    }

    public function getUsageMap(BlogMediaAsset $asset): array
    {
        $needles = array_filter([$asset->public_url, $asset->storage_path]);

        if ($needles === []) {
            return [];
        }

        $usage = [];

        BlogArticle::query()
            ->marketing()
            ->orderBy('id')
            ->chunk(100, function ($articles) use (&$usage, $needles): void {
                foreach ($articles as $article) {
                    $matches = $this->searchArticlePayload($article, $needles);

                    if ($matches !== []) {
                        $usage = array_merge($usage, $matches);
                    }
                }
            });

        return $usage;
    }

    private function searchArticlePayload(BlogArticle $article, array $needles): array
    {
        $payloads = [
            ['label' => 'featured_image', 'value' => $article->featured_image],
            ['label' => 'og_image', 'value' => $article->og_image],
        ];

        foreach ($article->gallery_images ?? [] as $index => $value) {
            $payloads[] = ['label' => 'gallery_images.' . $index, 'value' => $value];
        }

        $matches = [];

        foreach ($payloads as $payload) {
            if (in_array((string) $payload['value'], $needles, true)) {
                $matches[] = [
                    'type' => 'article_meta',
                    'article_id' => $article->id,
                    'article_title' => $article->title,
                    'field' => $payload['label'],
                ];
            }
        }

        return array_merge($matches, $this->searchArray($article->editor_document ?? [], $needles, [
            'type' => 'editor_document',
            'article_id' => $article->id,
            'article_title' => $article->title,
        ]));
    }

    private function searchArray(array $payload, array $needles, array $meta, string $path = ''): array
    {
        $matches = [];

        foreach ($payload as $key => $value) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $matches = array_merge($matches, $this->searchArray($value, $needles, $meta, $currentPath));
                continue;
            }

            if (in_array((string) $value, $needles, true)) {
                $matches[] = array_merge($meta, ['field' => $currentPath]);
            }
        }

        return $matches;
    }

    private function resolveContentOrganization(): Organization
    {
        $organizationId = (int) config('blog.platform_content_organization_id');

        if ($organizationId <= 0) {
            throw new RuntimeException('PLATFORM_CONTENT_ORGANIZATION_ID is not configured.');
        }

        return Organization::query()->findOrFail($organizationId);
    }

    private function resolveImageDimensions(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath() ?: '');

        if (!is_array($size)) {
            return [null, null];
        }

        return [$size[0] ?? null, $size[1] ?? null];
    }
}
