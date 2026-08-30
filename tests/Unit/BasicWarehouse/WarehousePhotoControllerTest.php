<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Controllers\WarehousePhotoController;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehousePhotoException;
use App\BusinessModules\Features\BasicWarehouse\Exceptions\WarehousePhotoTargetNotFoundException;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\WarehousePhotoUploadRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePhotoService;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class WarehousePhotoControllerTest extends TestCase
{
    public function test_unexpected_upload_failure_does_not_expose_internal_error(): void
    {
        $service = $this->mock(WarehousePhotoService::class);
        $service->shouldReceive('uploadAssetPhotos')
            ->once()
            ->andThrow(new RuntimeException('SQLSTATE[08006] connection failure'));
        Log::shouldReceive('error')->once();

        $response = (new WarehousePhotoController($service))->uploadAssetPhotos(
            $this->request(),
            41
        );

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            trans_message('warehouse_basic.photo_upload_failed', [], 'ru'),
            $response->getData(true)['message']
        );
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }

    public function test_expected_upload_failure_remains_a_readable_validation_response(): void
    {
        $message = trans_message('warehouse_basic.photo_limit_exceeded', [], 'ru');
        $service = $this->mock(WarehousePhotoService::class);
        $service->shouldReceive('uploadAssetPhotos')
            ->once()
            ->andThrow(new WarehousePhotoException($message));
        Log::shouldReceive('error')->never();

        $response = (new WarehousePhotoController($service))->uploadAssetPhotos(
            $this->request(),
            41
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($message, $response->getData(true)['message']);
    }

    public function test_missing_balance_photo_target_returns_not_found(): void
    {
        $service = $this->mock(WarehousePhotoService::class);
        $service->shouldReceive('uploadBalancePhotos')
            ->once()
            ->andThrow(new WarehousePhotoTargetNotFoundException());
        Log::shouldReceive('error')->never();

        $response = (new WarehousePhotoController($service))->uploadBalancePhotos(
            $this->request(),
            52,
            73
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            trans_message('warehouse_basic.photo_target_not_found', [], 'ru'),
            $response->getData(true)['message']
        );
    }

    private function request(): WarehousePhotoUploadRequest
    {
        $user = new User();
        $user->id = 17;
        $user->current_organization_id = 9;

        $request = WarehousePhotoUploadRequest::create('/api/v1/admin/assets/41/photos', 'POST');
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
