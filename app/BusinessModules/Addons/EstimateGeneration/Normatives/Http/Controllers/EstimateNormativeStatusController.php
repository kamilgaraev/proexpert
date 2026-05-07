<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import\EstimateImportStatisticsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class EstimateNormativeStatusController extends Controller
{
    public function index(Request $request, EstimateImportStatisticsService $statisticsService): JsonResponse
    {
        try {
            $validated = $request->validate([
                'source' => ['nullable', 'string', 'max:50'],
                'version' => ['nullable', 'string', 'max:100'],
                'errors_limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            ]);

            return AdminResponse::success(
                $statisticsService->inspect(
                    $validated['source'] ?? null,
                    $validated['version'] ?? null,
                    (int) ($validated['errors_limit'] ?? 50)
                )
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('[EstimateNormatives] Failed to load import status', [
                'source' => $request->input('source'),
                'version' => $request->input('version'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('auth.server_error'), 500);
        }
    }
}
