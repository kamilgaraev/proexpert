<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Exceptions\BusinessLogicException;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\WorkType;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\Interfaces\SupplierRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Services\Logging\LoggingService;
use App\Services\Material\MaterialService;
use App\Services\Supplier\SupplierService;
use App\Services\WorkType\WorkTypeService;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class CatalogDeletionGuardTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_work_type_delete_is_blocked_when_usage_exists(): void
    {
        $repository = Mockery::mock(WorkTypeRepositoryInterface::class);
        $repository->shouldReceive('delete')->never();

        $service = Mockery::mock(WorkTypeService::class, [$repository])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $workType = Mockery::mock(WorkType::class)->makePartial();
        $request = Request::create('/api/v1/admin/work-types/10', 'DELETE');

        $service->shouldReceive('findWorkTypeById')->once()->with(10, $request)->andReturn($workType);
        $service->shouldReceive('hasWorkTypeUsage')->once()->with($workType)->andReturn(true);

        $this->expectException(BusinessLogicException::class);

        $service->deleteWorkType(10, $request);
    }

    public function test_work_type_delete_is_allowed_when_usage_is_absent(): void
    {
        $repository = Mockery::mock(WorkTypeRepositoryInterface::class);
        $repository->shouldReceive('delete')->once()->with(10)->andReturn(true);

        $service = Mockery::mock(WorkTypeService::class, [$repository])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $workType = Mockery::mock(WorkType::class)->makePartial();
        $request = Request::create('/api/v1/admin/work-types/10', 'DELETE');

        $service->shouldReceive('findWorkTypeById')->once()->with(10, $request)->andReturn($workType);
        $service->shouldReceive('hasWorkTypeUsage')->once()->with($workType)->andReturn(false);

        $this->assertTrue($service->deleteWorkType(10, $request));
    }

    public function test_material_delete_is_blocked_when_usage_exists(): void
    {
        $repository = Mockery::mock(MaterialRepositoryInterface::class);
        $repository->shouldReceive('delete')->never();

        $service = Mockery::mock(MaterialService::class, [
            $repository,
            Mockery::mock(MeasurementUnitRepositoryInterface::class),
            Mockery::mock(LoggingService::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $material = Mockery::mock(Material::class)->makePartial();
        $request = Request::create('/api/v1/admin/materials/20', 'DELETE');

        $service->shouldReceive('findMaterialById')->once()->with(20, $request)->andReturn($material);
        $service->shouldReceive('hasMaterialUsage')->once()->with($material)->andReturn(true);

        $this->expectException(BusinessLogicException::class);

        $service->deleteMaterial(20, $request);
    }

    public function test_material_delete_is_allowed_when_usage_is_absent(): void
    {
        $repository = Mockery::mock(MaterialRepositoryInterface::class);
        $repository->shouldReceive('delete')->once()->with(20)->andReturn(true);

        $service = Mockery::mock(MaterialService::class, [
            $repository,
            Mockery::mock(MeasurementUnitRepositoryInterface::class),
            Mockery::mock(LoggingService::class),
        ])->makePartial()->shouldAllowMockingProtectedMethods();

        $material = Mockery::mock(Material::class)->makePartial();
        $request = Request::create('/api/v1/admin/materials/20', 'DELETE');

        $service->shouldReceive('findMaterialById')->once()->with(20, $request)->andReturn($material);
        $service->shouldReceive('hasMaterialUsage')->once()->with($material)->andReturn(false);

        $this->assertTrue($service->deleteMaterial(20, $request));
    }

    public function test_supplier_delete_is_blocked_when_usage_exists(): void
    {
        $repository = Mockery::mock(SupplierRepositoryInterface::class);
        $repository->shouldReceive('delete')->never();

        $service = Mockery::mock(SupplierService::class, [$repository])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $supplier = Mockery::mock(Supplier::class)->makePartial();
        $request = Request::create('/api/v1/admin/suppliers/30', 'DELETE');

        $service->shouldReceive('findSupplierById')->once()->with(30, $request)->andReturn($supplier);
        $service->shouldReceive('hasSupplierUsage')->once()->with($supplier)->andReturn(true);

        $this->expectException(BusinessLogicException::class);

        $service->deleteSupplier(30, $request);
    }

    public function test_supplier_delete_is_allowed_when_usage_is_absent(): void
    {
        $repository = Mockery::mock(SupplierRepositoryInterface::class);
        $repository->shouldReceive('delete')->once()->with(30)->andReturn(true);

        $service = Mockery::mock(SupplierService::class, [$repository])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $supplier = Mockery::mock(Supplier::class)->makePartial();
        $request = Request::create('/api/v1/admin/suppliers/30', 'DELETE');

        $service->shouldReceive('findSupplierById')->once()->with(30, $request)->andReturn($supplier);
        $service->shouldReceive('hasSupplierUsage')->once()->with($supplier)->andReturn(false);

        $this->assertTrue($service->deleteSupplier(30, $request));
    }
}
