<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseIdentifier;
use App\Services\Export\PdfExporterService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetLabelExportService
{
    public function __construct(
        protected PdfExporterService $pdfExporterService
    ) {
    }

    public function export(int $organizationId, array $filters = []): StreamedResponse
    {
        $layout = (int) ($filters['layout'] ?? 6);
        $assets = $this->getAssetsForExport($organizationId, $filters);

        if ($assets->isEmpty()) {
            throw new \InvalidArgumentException(trans_message('basic_warehouse.asset.labels_empty'));
        }

        $identifiers = $this->ensureQrIdentifiers($organizationId, $assets);
        $layoutConfig = $this->getLayoutConfig($layout);

        $labels = $assets->map(function (Asset $asset) use ($identifiers): array {
            $identifier = $identifiers->get($asset->id);
            $assetTypeLabel = Asset::getAssetTypes()[$asset->asset_type] ?? $asset->asset_type;

            return [
                'asset_id' => $asset->id,
                'name' => $asset->name,
                'article' => $asset->code ?: 'Без артикула',
                'asset_type' => $assetTypeLabel,
                'category' => $asset->asset_category ?: ($asset->category ?: null),
                'label_code' => $identifier->code,
                'qr_image' => $this->makeQrDataUri($identifier->code),
            ];
        })->values()->all();

        return $this->pdfExporterService->streamDownload(
            'warehouse.exports.asset-label-sheet',
            [
                'labels' => $labels,
                'layout' => $layoutConfig,
                'generatedAt' => now()->format('d.m.Y H:i'),
            ],
            'warehouse-asset-labels-' . now()->format('Y-m-d_H-i') . '.pdf'
        );
    }

    private function getAssetsForExport(int $organizationId, array $filters): Collection
    {
        $query = Asset::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name');

        if (! empty($filters['asset_ids']) && is_array($filters['asset_ids'])) {
            $query->whereIn('id', $filters['asset_ids']);
        }

        if (! empty($filters['asset_type'])) {
            $query->ofType((string) $filters['asset_type']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);

            $query->where(function ($nestedQuery) use ($search): void {
                $nestedQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return $query->get();
    }

    private function ensureQrIdentifiers(int $organizationId, Collection $assets): Collection
    {
        $assetIds = $assets->pluck('id')->all();

        $existingQrIdentifiers = WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', 'asset')
            ->whereIn('entity_id', $assetIds)
            ->where('identifier_type', WarehouseIdentifier::TYPE_QR)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('entity_id')
            ->map(fn (Collection $group) => $group->first());

        $existingPrimaryIdentifiers = WarehouseIdentifier::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', 'asset')
            ->whereIn('entity_id', $assetIds)
            ->where('is_primary', true)
            ->pluck('id', 'entity_id');

        foreach ($assets as $asset) {
            if ($existingQrIdentifiers->has($asset->id)) {
                continue;
            }

            $identifier = WarehouseIdentifier::create([
                'organization_id' => $organizationId,
                'warehouse_id' => null,
                'identifier_type' => WarehouseIdentifier::TYPE_QR,
                'code' => $this->generateAssetIdentifierCode($organizationId, (int) $asset->id),
                'entity_type' => 'asset',
                'entity_id' => $asset->id,
                'label' => $this->normalizeIdentifierLabel($asset->name),
                'status' => WarehouseIdentifier::STATUS_ACTIVE,
                'is_primary' => ! $existingPrimaryIdentifiers->has($asset->id),
                'assigned_at' => now(),
            ]);

            $existingQrIdentifiers->put($asset->id, $identifier);
        }

        return $existingQrIdentifiers;
    }

    private function generateAssetIdentifierCode(int $organizationId, int $assetId): string
    {
        return sprintf('AST-%d-%06d', $organizationId, $assetId);
    }

    private function normalizeIdentifierLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        return Str::limit(Str::squish($label), 255, '');
    }

    private function makeQrDataUri(string $payload): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 6,
            'imageBase64' => false,
            'quietzoneSize' => 2,
        ]);

        $svg = (new QRCode($options))->render($payload);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function getLayoutConfig(int $layout): array
    {
        if ($layout === 4) {
            return [
                'itemsPerPage' => 4,
                'columns' => 2,
                'labelHeight' => '134mm',
                'labelPadding' => '9mm',
                'qrSize' => '42mm',
                'nameSize' => '13px',
                'metaSize' => '10px',
                'codeSize' => '9px',
                'cutNote' => 'Формат 2x2, крупная метка для печати на A4.',
            ];
        }

        return [
            'itemsPerPage' => 6,
            'columns' => 2,
            'labelHeight' => '88mm',
            'labelPadding' => '7mm',
            'qrSize' => '34mm',
            'nameSize' => '11px',
            'metaSize' => '9px',
            'codeSize' => '8px',
            'cutNote' => 'Формат 2x3, оптимален для печати и нарезки на складе.',
        ];
    }
}
