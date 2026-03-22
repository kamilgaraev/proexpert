<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AssetManagerService
{
    public function __construct(
        private readonly FileService $fileService
    ) {
    }

    public function uploadAsset(HoldingSite $site, UploadedFile $file, string $usageContext, User $uploader): SiteAsset
    {
        $organization = $site->organizationGroup->parentOrganization;
        $directory = sprintf('holding-sites/site-%d/%s', $site->id, $usageContext ?: 'general');
        $storagePath = $this->fileService->upload($file, $directory, null, 'public', $organization, true);

        if (!$storagePath) {
            throw new \RuntimeException('Failed to upload asset to S3.');
        }

        $publicUrl = $this->resolvePublicUrl($site, $storagePath);

        return SiteAsset::create([
            'holding_site_id' => $site->id,
            'filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'public_url' => $publicUrl,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize(),
            'metadata' => $this->extractMetadata($file),
            'asset_type' => $this->determineAssetType($file),
            'usage_context' => $usageContext ?: 'general',
            'is_optimized' => false,
            'optimized_variants' => [],
            'uploaded_by_user_id' => $uploader->id,
        ]);
    }

    public function getSiteAssets(HoldingSite $site, ?string $assetType = null, ?string $usageContext = null): array
    {
        $query = $site->assets()->with('uploader')->orderByDesc('created_at');

        if ($assetType) {
            $query->where('asset_type', $assetType);
        }

        if ($usageContext) {
            $query->where('usage_context', $usageContext);
        }

        return $query->get()->map(fn (SiteAsset $asset) => $this->serializeAsset($site, $asset))->values()->all();
    }

    public function updateAssetMetadata(SiteAsset $asset, array $metadata): bool
    {
        $currentMetadata = $asset->metadata ?? [];

        return $asset->update([
            'metadata' => array_merge($currentMetadata, $metadata),
        ]);
    }

    public function deleteAsset(SiteAsset $asset, bool $force = false): bool
    {
        $site = $asset->holdingSite;
        $usageMap = $this->getAssetUsageMap($site, $asset);

        if (!$force && !empty($usageMap)) {
            throw new \RuntimeException('Asset is in use.');
        }

        $organization = $site->organizationGroup->parentOrganization;
        $this->fileService->delete($asset->storage_path, $organization);

        foreach ($asset->optimized_variants ?? [] as $variantPath) {
            if (is_string($variantPath) && !str_starts_with($variantPath, 'http')) {
                $this->fileService->delete($variantPath, $organization);
            }
        }

        $deleted = $asset->delete();

        if ($deleted) {
            $site->clearCache();
        }

        return $deleted;
    }

    public function serializeAsset(HoldingSite $site, SiteAsset $asset): array
    {
        $publicUrl = $this->resolvePublicUrl($site, $asset->storage_path);
        $optimized = $asset->optimized_variants ?? [];
        $usageMap = $this->buildAssetUsageMap($site, $asset, $publicUrl);

        if ($publicUrl !== '' && $asset->public_url !== $publicUrl) {
            $asset->forceFill(['public_url' => $publicUrl])->saveQuietly();
        }

        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'public_url' => $publicUrl,
            'optimized_url' => [
                'thumbnail' => $optimized['thumbnail'] ?? $publicUrl,
                'small' => $optimized['small'] ?? $publicUrl,
                'medium' => $optimized['medium'] ?? $publicUrl,
                'large' => $optimized['large'] ?? $publicUrl,
                'original' => $publicUrl,
            ],
            'mime_type' => $asset->mime_type,
            'file_size' => $asset->file_size,
            'human_size' => $asset->getHumanReadableSize(),
            'asset_type' => $asset->asset_type,
            'usage_context' => $asset->usage_context,
            'metadata' => $asset->metadata ?? [],
            'is_optimized' => $asset->is_optimized,
            'uploaded_at' => optional($asset->created_at)?->toISOString(),
            'uploader' => [
                'id' => $asset->uploader?->id,
                'name' => $asset->uploader?->name,
            ],
            'usage_map' => $usageMap,
            'safe_delete' => empty($usageMap),
        ];
    }

    public function getAssetUsageMap(HoldingSite $site, SiteAsset $asset): array
    {
        return $this->buildAssetUsageMap($site, $asset, $asset->public_url);
    }

    private function buildAssetUsageMap(HoldingSite $site, SiteAsset $asset, string $publicUrl): array
    {
        $needles = array_filter([
            $publicUrl,
            $asset->storage_path,
            (string) $asset->id,
        ]);

        $usage = [];

        foreach (['logo_url', 'favicon_url'] as $siteField) {
            if (in_array((string) $site->{$siteField}, $needles, true)) {
                $usage[] = [
                    'type' => 'site',
                    'field' => $siteField,
                ];
            }
        }

        foreach ($site->contentBlocks()->get(['id', 'block_key', 'block_type', 'content', 'settings']) as $block) {
            $usage = array_merge(
                $usage,
                $this->searchInPayload($needles, $block->content ?? [], [
                    'type' => 'block_content',
                    'block_id' => $block->id,
                    'block_key' => $block->block_key,
                    'block_type' => $block->block_type,
                ]),
                $this->searchInPayload($needles, $block->settings ?? [], [
                    'type' => 'block_settings',
                    'block_id' => $block->id,
                    'block_key' => $block->block_key,
                    'block_type' => $block->block_type,
                ])
            );
        }

        return $usage;
    }

    public static function getAllowedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'video/mp4',
            'video/webm',
        ];
    }

    public static function getAllowedUsageContexts(): array
    {
        return [
            'hero',
            'stats',
            'about',
            'services',
            'projects',
            'team',
            'testimonials',
            'gallery',
            'faq',
            'lead_form',
            'contacts',
            'logo',
            'favicon',
            'general',
        ];
    }

    public static function getMaxFileSize(): int
    {
        return 10 * 1024 * 1024;
    }

    private function resolvePublicUrl(HoldingSite $site, string $storagePath): string
    {
        $organization = $site->organizationGroup->parentOrganization;

        return $this->fileService->publicUrl($storagePath, $organization)
            ?? $this->fileService->url($storagePath, $organization)
            ?? '';
    }

    private function searchInPayload(array $needles, array $payload, array $baseMeta, string $path = ''): array
    {
        $usage = [];

        foreach ($payload as $key => $value) {
            $currentPath = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $usage = array_merge($usage, $this->searchInPayload($needles, $value, $baseMeta, $currentPath));
                continue;
            }

            if (in_array((string) $value, $needles, true)) {
                $usage[] = array_merge($baseMeta, ['field_path' => $currentPath]);
            }
        }

        return $usage;
    }

    private function extractMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toISOString(),
        ];

        if (($file->getMimeType() ?? '') !== '' && str_starts_with((string) $file->getMimeType(), 'image/')) {
            $size = @getimagesize($file->getRealPath());

            if (is_array($size)) {
                $metadata['width'] = $size[0] ?? null;
                $metadata['height'] = $size[1] ?? null;
                $metadata['aspect_ratio'] = isset($size[0], $size[1]) && $size[1] ? round($size[0] / $size[1], 2) : null;
            }
        }

        return Arr::whereNotNull($metadata);
    }

    private function determineAssetType(UploadedFile $file): string
    {
        $mimeType = (string) $file->getMimeType();
        $filename = Str::lower($file->getClientOriginalName());

        if (str_starts_with($mimeType, 'image/')) {
            if (in_array($mimeType, ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
                return 'icon';
            }

            if (str_contains($filename, 'logo')) {
                return 'logo';
            }

            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        return 'document';
    }
}
