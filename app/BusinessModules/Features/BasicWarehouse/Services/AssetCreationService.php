<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class AssetCreationService
{
    public function __construct(
        private AssetService $assets,
        private SerializedAssetReceiptService $serializedAssets,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, int $actorId, array $data): Asset
    {
        $instances = $data['instances'] ?? [];
        unset($data['instances']);

        if ($instances === []) {
            return $this->assets->createAsset($organizationId, $data);
        }

        return DB::transaction(function () use ($organizationId, $actorId, $data, $instances): Asset {
            $asset = $this->assets->createAsset($organizationId, $data);

            try {
                $this->serializedAssets->receive(
                    $organizationId,
                    (int) $asset->id,
                    (int) $data['warehouse_id'],
                    $actorId,
                    $instances,
                );
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23505') {
                    throw new DomainException(trans_message('asset_management.errors.duplicate_identity'));
                }

                throw $exception;
            }

            return $asset;
        });
    }
}
