<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogMediaAsset;
use App\Models\Organization;
use App\Models\SystemAdmin;
use App\Services\Filament\SystemAdminAuditService;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BlogMediaService
{
    private const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const DOCUMENT_MIME_TYPES = [
        'application/pdf',
    ];

    private const MAX_UPLOAD_SIZE_KILOBYTES = 10240;

    public function __construct(
        private readonly FileService $fileService,
        private readonly SystemAdminAuditService $auditService,
    ) {
    }

    public static function allowedMimeTypes(): array
    {
        return array_merge(self::IMAGE_MIME_TYPES, self::DOCUMENT_MIME_TYPES);
    }

    public static function maxUploadSizeKilobytes(): int
    {
        return self::MAX_UPLOAD_SIZE_KILOBYTES;
    }

    public function uploadMarketingAsset(UploadedFile $file, SystemAdmin $systemAdmin, array $meta = []): BlogMediaAsset
    {
        $this->validateUpload($file, $meta);

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
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream',
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
            throw ValidationException::withMessages([
                'media_asset' => [trans_message('blog_cms.media_delete_used')],
            ]);
        }

        $this->fileService->delete($asset->storage_path, $this->resolveContentOrganization());
        $asset->delete();
    }

    public function ensureCanReplaceDraftReferences(BlogMediaAsset $asset): void
    {
        $usage = $this->refreshUsageMetadata($asset);
        $blockedUsage = collect($usage)
            ->filter(fn (array $item): bool => ($item['article_status'] ?? null) !== BlogArticleStatusEnum::DRAFT->value)
            ->values();

        if ($blockedUsage->isNotEmpty()) {
            throw ValidationException::withMessages([
                'media_asset' => [trans_message('blog_cms.media_replace_published_used')],
            ]);
        }
    }

    public function replaceWithUploadedFile(BlogMediaAsset $asset, UploadedFile $file, SystemAdmin $systemAdmin, array $meta = []): BlogMediaAsset
    {
        $this->ensureCanReplaceDraftReferences($asset);

        $newAsset = $this->uploadMarketingAsset($file, $systemAdmin, $meta);
        $this->replaceDraftReferences($asset, $newAsset, $systemAdmin);

        return $newAsset;
    }

    public function replaceDraftReferences(BlogMediaAsset $oldAsset, BlogMediaAsset $newAsset, SystemAdmin $systemAdmin): int
    {
        $this->ensureCanReplaceDraftReferences($oldAsset);
        $usage = $this->getUsageMap($oldAsset);
        $articleIds = collect($usage)
            ->pluck('article_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $updatedCount = DB::transaction(function () use ($articleIds, $oldAsset, $newAsset): int {
            $count = 0;

            BlogArticle::query()
                ->whereIn('id', $articleIds)
                ->where('status', BlogArticleStatusEnum::DRAFT->value)
                ->get()
                ->each(function (BlogArticle $article) use ($oldAsset, $newAsset, &$count): void {
                    if ($this->replaceArticleReferences($article, $oldAsset, $newAsset)) {
                        $count++;
                    }
                });

            return $count;
        });

        $this->refreshUsageMetadata($oldAsset);
        $this->refreshUsageMetadata($newAsset);
        $this->recordReplaceAudit($oldAsset, $newAsset, $systemAdmin, $updatedCount);

        return $updatedCount;
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
                    'article_status' => $article->status->value,
                    'field' => $payload['label'],
                ];
            }
        }

        return array_merge($matches, $this->searchArray($article->editor_document ?? [], $needles, [
            'type' => 'editor_document',
            'article_id' => $article->id,
            'article_title' => $article->title,
            'article_status' => $article->status->value,
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

    private function validateUpload(UploadedFile $file, array $meta): void
    {
        $mimeType = $file->getMimeType() ?? $file->getClientMimeType() ?? '';
        $errors = [];

        if (!in_array($mimeType, self::allowedMimeTypes(), true)) {
            $errors['upload_file'] = [trans_message('blog_cms.media_upload_type_invalid')];
        }

        if (($file->getSize() ?: 0) > self::MAX_UPLOAD_SIZE_KILOBYTES * 1024) {
            $errors['upload_file'] = [trans_message('blog_cms.media_upload_size_invalid')];
        }

        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true) && @getimagesize($file->getRealPath() ?: '') === false) {
            $errors['upload_file'] = [trans_message('blog_cms.media_upload_image_invalid')];
        }

        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true) && blank(Arr::get($meta, 'alt_text'))) {
            $errors['alt_text'] = [trans_message('blog_cms.media_alt_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function replaceArticleReferences(BlogArticle $article, BlogMediaAsset $oldAsset, BlogMediaAsset $newAsset): bool
    {
        $replacements = array_filter([
            $oldAsset->public_url => $newAsset->public_url,
            $oldAsset->storage_path => $newAsset->storage_path,
        ]);
        $payload = [
            'featured_image' => $this->replaceScalar($article->featured_image, $replacements),
            'og_image' => $this->replaceScalar($article->og_image, $replacements),
            'gallery_images' => $this->replaceInArray($article->gallery_images ?? [], $replacements),
            'editor_document' => $this->replaceInArray($article->editor_document ?? [], $replacements),
        ];

        $changed = $payload['featured_image'] !== $article->featured_image
            || $payload['og_image'] !== $article->og_image
            || $payload['gallery_images'] !== ($article->gallery_images ?? [])
            || $payload['editor_document'] !== ($article->editor_document ?? []);

        if (!$changed) {
            return false;
        }

        $article->forceFill($payload)->save();

        return true;
    }

    private function replaceScalar(?string $value, array $replacements): ?string
    {
        if ($value === null) {
            return null;
        }

        return $replacements[$value] ?? $value;
    }

    private function replaceInArray(array $payload, array $replacements): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->replaceInArray($value, $replacements);
                continue;
            }

            if (is_string($value) && array_key_exists($value, $replacements)) {
                $payload[$key] = $replacements[$value];
            }
        }

        return $payload;
    }

    private function recordReplaceAudit(BlogMediaAsset $oldAsset, BlogMediaAsset $newAsset, SystemAdmin $systemAdmin, int $updatedCount): void
    {
        $this->auditService->record(
            actor: $systemAdmin,
            eventType: 'system_admin.blog_media.replaced',
            action: ActivityActionEnum::Updated,
            subjectType: BlogMediaAsset::class,
            subjectId: $oldAsset->id,
            subjectLabel: $oldAsset->filename,
            title: trans_message('blog_cms.media_replace_audit_title'),
            description: trans_message('blog_cms.media_replace_audit_description'),
            before: [
                'asset_id' => $oldAsset->id,
                'public_url' => $oldAsset->public_url,
            ],
            after: [
                'asset_id' => $newAsset->id,
                'public_url' => $newAsset->public_url,
                'updated_articles' => $updatedCount,
            ],
        );
    }
}
