<?php

declare(strict_types=1);

namespace Tests\Unit\WorkVolumes;

use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageConversion;
use App\Exceptions\BusinessLogicException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class WorkVolumeCoverageConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $loader = new FileLoader(new Filesystem, dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang');
        $container->instance('translator', new Translator($loader, 'ru'));
        $container->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $container->instance('app', new class {
            public function getLocale(): string { return 'ru'; }
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_same_unit_is_formatted_without_basis(): void
    {
        self::assertSame(['quantity' => '12.340000', 'basis' => null], (new WorkVolumeCoverageConversion())->convert('12.34', 'м2', 'м2', null));
    }

    public function test_different_units_are_converted_and_basis_is_normalized(): void
    {
        self::assertSame([
            'quantity' => '2.500000',
            'basis' => ['coefficient' => '0.5', 'reason' => 'Таблица', 'from_unit' => 'м2', 'to_unit' => 'м3', 'source_link_id' => 7],
        ], (new WorkVolumeCoverageConversion())->convert('5', 'м2', 'м3', [
            'coefficient' => '0.500000', 'reason' => '  Таблица  ', 'source_link_id' => '7',
        ]));
    }

    public function test_missing_basis_is_rejected_for_different_units(): void
    {
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        (new WorkVolumeCoverageConversion())->convert('1', 'м2', 'м3', null);
    }

    public function test_basis_is_rejected_for_same_units(): void
    {
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        (new WorkVolumeCoverageConversion())->convert('1', 'м2', 'м2', ['coefficient' => '1', 'reason' => 'x']);
    }

    public function test_non_positive_integer_source_link_id_is_rejected(): void
    {
        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(422);
        (new WorkVolumeCoverageConversion())->convert('1', 'м2', 'м3', ['coefficient' => '1', 'reason' => 'x', 'source_link_id' => 0]);
    }

    public function test_rounding_and_overflow_are_rejected(): void
    {
        $converter = new WorkVolumeCoverageConversion();
        try {
            $converter->convert('1', 'a', 'b', ['coefficient' => '0.3333333', 'reason' => 'x']);
            self::fail('Expected exact-scale rejection.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
        $this->expectExceptionCode(422);
        $converter->convert('999999999999999999', 'a', 'b', ['coefficient' => '10', 'reason' => 'x']);
    }
}
