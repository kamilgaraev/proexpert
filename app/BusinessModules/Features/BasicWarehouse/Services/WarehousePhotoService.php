<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseItemGallery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Models\File;
use App\Models\Material;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehousePhotoService
{
    private const MAX_PHOTOS = 4;

    public function __construct(
        private readonly FileService $fileService
    ) {
    }

    public function getAsset(int $organizationId, int $assetId): Asset
    {
        return Asset::with('photos')
            ->where('organization_id', $organizationId)
            ->findOrFail($assetId);
    }

    public function getMovement(int $organizationId, int $movementId): WarehouseMovement
    {
        return WarehouseMovement::with('photos')
            ->where('organization_id', $organizationId)
            ->findOrFail($movementId);
    }

    public function getBalanceGallery(int $organizationId, int $warehouseId, int $materialId): WarehouseItemGallery
    {
        $this->assertWarehouseMaterialBelongsToOrganization($organizationId, $warehouseId, $materialId);

        return WarehouseItemGallery::with('photos')->firstOrCreate([
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
        ]);
    }

    public function uploadAssetPhotos(int $organizationId, int $assetId, iterable $photos, User $user): array
    {
        $asset = $this->getAsset($organizationId, $assetId);

        return $this->uploadPhotos($asset, $photos, $user, 'warehouse/assets/' . $asset->id, 'asset');
    }

    public function uploadMovementPhotos(int $organizationId, int $movementId, iterable $photos, User $user): array
    {
        $movement = $this->getMovement($organizationId, $movementId);

        return $this->uploadPhotos($movement, $photos, $user, 'warehouse/movements/' . $movement->id, 'receipt');
    }

    public function uploadBalancePhotos(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        iterable $photos,
        User $user
    ): array {
        $gallery = $this->getBalanceGallery($organizationId, $warehouseId, $materialId);

        return $this->uploadPhotos(
            $gallery,
            $photos,
            $user,
            'warehouse/balances/warehouse-' . $warehouseId . '/material-' . $materialId,
            'balance'
        );
    }

    public function deleteAssetPhoto(int $organizationId, int $assetId, int $fileId): void
    {
        $asset = $this->getAsset($organizationId, $assetId);
        $this->deletePhoto($asset, $fileId);
    }

    public function deleteMovementPhoto(int $organizationId, int $movementId, int $fileId): void
    {
        $movement = $this->getMovement($organizationId, $movementId);
        $this->deletePhoto($movement, $fileId);
    }

    public function deleteBalancePhoto(int $organizationId, int $warehouseId, int $materialId, int $fileId): void
    {
        $gallery = $this->getBalanceGallery($organizationId, $warehouseId, $materialId);
        $this->deletePhoto($gallery, $fileId);
    }

    public function getBalancePhotoMap(int $organizationId, Collection $pairs): array
    {
        if ($pairs->isEmpty()) {
            return [];
        }

        $warehouseIds = $pairs->pluck('warehouse_id')->unique()->values();
        $materialIds = $pairs->pluck('material_id')->unique()->values();

        $galleries = WarehouseItemGallery::with('photos')
            ->where('organization_id', $organizationId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('material_id', $materialIds)
            ->get();

        return $galleries->mapWithKeys(
            static fn (WarehouseItemGallery $gallery): array => [
                $gallery->warehouse_id . ':' . $gallery->material_id => $gallery->photo_gallery,
            ]
        )->all();
    }

    private function uploadPhotos(Model $owner, iterable $photos, User $user, string $directory, string $category): array
    {
        $photoFiles = $this->normalizePhotos($photos);
        $existingCount = $owner->photos()->count();

        if ($existingCount + count($photoFiles) > self::MAX_PHOTOS) {
            throw new RuntimeException(__('warehouse_basic.photo_limit_exceeded'));
        }

        $organization = Organization::findOrFail((int) $user->current_organization_id);

        return DB::transaction(function () use ($owner, $photoFiles, $user, $directory, $category, $organization): array {
            $created = [];

            foreach ($photoFiles as $photo) {
                $path = $this->fileService->upload($photo, $directory, null, 'public', $organization);

                if ($path === false) {
                    throw new RuntimeException(__('warehouse_basic.photo_upload_failed'));
                }

                $file = $owner->files()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'name' => basename($path),
                    'original_name' => $photo->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $photo->getClientMimeType() ?? $photo->getMimeType() ?? 'application/octet-stream',
                    'size' => $photo->getSize() ?? 0,
                    'disk' => 's3',
                    'type' => 'photo',
                    'category' => $category,
                ]);

                $created[] = $this->mapFile($file);
            }

            return $created;
        });
    }

    private function deletePhoto(Model $owner, int $fileId): void
    {
        $file = $owner->photos()->whereKey($fileId)->firstOrFail();
        $this->fileService->delete($file->path);
        $file->delete();
    }

    private function normalizePhotos(iterable $photos): array
    {
        $result = [];

        foreach ($photos as $photo) {
            if ($photo instanceof UploadedFile) {
                $result[] = $photo;
            }
        }

        if ($result === []) {
            throw new RuntimeException(__('warehouse_basic.photo_upload_empty'));
        }

        return $result;
    }

    private function assertWarehouseMaterialBelongsToOrganization(int $organizationId, int $warehouseId, int $materialId): void
    {
        $warehouseExists = OrganizationWarehouse::where('organization_id', $organizationId)->whereKey($warehouseId)->exists();
        $materialExists = Material::where('organization_id', $organizationId)->whereKey($materialId)->exists();

        if (!$warehouseExists || !$materialExists) {
            throw new RuntimeException(__('warehouse_basic.photo_target_not_found'));
        }
    }

    private function mapFile(File $file): array
    {
        $url = $this->fileService->temporaryUrl($file->path, 60) ?? $file->url;

        return [
            'id' => $file->id,
            'name' => $file->name,
            'original_name' => $file->original_name,
            'url' => $url,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'category' => $file->category,
            'uploaded_at' => optional($file->created_at)?->toDateTimeString(),
        ];
    }
}
