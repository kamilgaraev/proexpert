<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WarehousePassportService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly AuthorizationService $authorization,
    ) {}

    public function references(int $organizationId, int $projectId): array
    {
        return WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where(static fn ($query) => $query->where('project_id', $projectId)->orWhereNull('project_id'))
            ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
            ->whereHas('passportFile')
            ->with(['passportFile', 'material:id,name'])
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(static function (WarehouseMovement $movement): array {
                $file = $movement->passportFile;

                return [
                    'id' => (int) $file->id,
                    'movement_id' => (int) $movement->id,
                    'material_name' => $movement->material?->name,
                    'batch_number' => data_get($movement->metadata, 'batch_number'),
                    'document_number' => $movement->document_number,
                    'file_name' => $file->original_name,
                    'url' => $movement->passport_document['url'],
                ];
            })
            ->all();
    }

    public function upload(int $organizationId, int $movementId, UploadedFile $passport, User $user): array
    {
        if ((int) $user->current_organization_id !== $organizationId
            || ! $this->authorization->can($user, 'warehouse.receipts', ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(trans_message('errors.unauthorized'), 403);
        }

        return DB::transaction(function () use ($organizationId, $movementId, $passport, $user): array {
            $movement = WarehouseMovement::query()
                ->where('organization_id', $organizationId)
                ->where('movement_type', WarehouseMovement::TYPE_RECEIPT)
                ->lockForUpdate()
                ->findOrFail($movementId);

            $existing = $movement->passportFile()->first();
            if ($existing !== null) {
                return $movement->load('passportFile')->passport_document;
            }

            $organization = Organization::findOrFail($organizationId);
            $path = $this->fileService->upload(
                $passport,
                'warehouse/movements/'.$movementId.'/passport',
                null,
                'private',
                $organization
            );
            if ($path === false) {
                throw new RuntimeException('warehouse_passport_upload_failed');
            }

            $movement->files()->create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'name' => basename($path),
                'original_name' => $passport->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $passport->getClientMimeType() ?? $passport->getMimeType() ?? 'application/octet-stream',
                'size' => $passport->getSize() ?? 0,
                'disk' => 's3',
                'type' => 'document',
                'category' => 'receipt_passport',
            ]);

            return $movement->load('passportFile')->passport_document;
        });
    }
}
