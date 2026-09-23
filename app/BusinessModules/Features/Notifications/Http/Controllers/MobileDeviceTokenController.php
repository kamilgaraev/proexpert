<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Http\Requests\RegisterMobileDeviceRequest;
use App\BusinessModules\Features\Notifications\Services\MobileDeviceTokenService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileDeviceTokenController extends Controller
{
    public function __construct(private readonly MobileDeviceTokenService $service) {}

    public function store(RegisterMobileDeviceRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $device = $this->service->register(
            $request->user(),
            $payload['installation_id'],
            $payload['platform'],
            $payload['provider'],
            $payload['token'],
        );

        return MobileResponse::success([
            'installation_id' => $device->installation_id,
            'platform' => $device->platform,
            'provider' => $device->provider,
        ]);
    }

    public function destroy(Request $request, string $installationId): JsonResponse
    {
        $this->service->unregister($request->user(), $installationId);

        return MobileResponse::success(['installation_id' => $installationId]);
    }
}
