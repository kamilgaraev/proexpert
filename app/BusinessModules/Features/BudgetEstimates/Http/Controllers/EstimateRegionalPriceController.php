<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceActivation;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs\RegionalPriceActivationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class EstimateRegionalPriceController extends Controller
{
    public function options(): JsonResponse
    {
        try {
            $regions = EstimateRegion::query()
                ->where('is_supported', true)
                ->with('priceZones')
                ->orderBy('name')
                ->get();

            $activeVersionIds = EstimateRegionalPriceActivation::query()
                ->pluck('active_version_id')
                ->all();

            $versions = EstimateRegionalPriceVersion::query()
                ->with(['region', 'priceZone', 'period'])
                ->orderByDesc('id')
                ->get();

            return AdminResponse::success([
                'regions' => $regions->map(static fn (EstimateRegion $region): array => [
                    'id' => $region->id,
                    'code' => $region->code,
                    'name' => $region->name,
                    'fgiscs_subject_id' => $region->fgiscs_subject_id,
                    'price_zones' => $region->priceZones->map(static fn ($zone): array => [
                        'id' => $zone->id,
                        'region_id' => $zone->estimate_region_id,
                        'name' => $zone->name,
                        'fgiscs_price_zone_id' => $zone->fgiscs_price_zone_id,
                    ])->values()->all(),
                ])->values()->all(),
                'versions' => $versions->map(static fn (EstimateRegionalPriceVersion $version): array => [
                    'id' => $version->id,
                    'source' => $version->source,
                    'version_key' => $version->version_key,
                    'status' => $version->status?->value,
                    'is_active' => in_array($version->id, $activeVersionIds, true),
                    'region_id' => $version->region_id,
                    'price_zone_id' => $version->price_zone_id,
                    'period_id' => $version->period_id,
                    'period_name' => $version->period?->name,
                    'year' => $version->period?->year,
                    'quarter' => $version->period?->quarter,
                    'activated_at' => $version->activated_at?->toISOString(),
                ])->values()->all(),
            ]);
        } catch (Throwable $exception) {
            Log::error('estimate_regional_prices.options_failed', [
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('estimate.operation_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function rollback(RegionalPriceActivationService $activationService): JsonResponse
    {
        try {
            $activation = $activationService->rollback('RU-TA', 202);

            return AdminResponse::success([
                'active_version_id' => $activation->active_version_id,
                'active_period' => $activation->activeVersion?->period?->name,
                'previous_version_id' => $activation->previous_version_id,
                'previous_period' => $activation->previousVersion?->period?->name,
            ]);
        } catch (Throwable $exception) {
            Log::error('estimate_regional_prices.rollback_failed', [
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
