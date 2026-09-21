<?php

declare(strict_types=1);

namespace Tests\Unit\HandoverAcceptance;

use App\BusinessModules\Features\HandoverAcceptance\Services\TechnicalAcceptanceQuantityService;
use App\Exceptions\BusinessLogicException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
final class TechnicalAcceptanceQuantityServiceTest extends TestCase
{
    private Container $originalContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $loader = new ArrayLoader();
        $loader->addMessages('ru', 'technical_acceptance', require dirname(__DIR__, 3).'/lang/ru/technical_acceptance.php');
        $app->instance('translator', new Translator($loader, 'ru'));
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_quantity_split_preserves_six_decimal_places(): void
    {
        $lines = $this->canonical([
            ['completed_work_id' => 17, 'unit_id' => 4, 'presented_quantity' => '100.123456', 'accepted_quantity' => '80.123456', 'defect_quantity' => '20', 'defect_reason' => 'Замечание'],
        ]);

        self::assertSame('100.123456', $lines[17]['presented_quantity']);
        self::assertSame('80.123456', $lines[17]['accepted_quantity']);
        self::assertSame('20.000000', $lines[17]['defect_quantity']);
    }

    public function test_duplicate_or_empty_work_lines_are_rejected(): void
    {
        $line = ['completed_work_id' => 17, 'unit_id' => 4, 'presented_quantity' => '1', 'accepted_quantity' => '1', 'defect_quantity' => '0'];
        $this->expectException(BusinessLogicException::class);
        $this->canonical([$line, $line]);
    }

    public function test_split_must_balance_exactly(): void
    {
        $this->expectException(BusinessLogicException::class);
        $this->canonical([
            ['completed_work_id' => 17, 'unit_id' => 4, 'presented_quantity' => '100', 'accepted_quantity' => '80', 'defect_quantity' => '19.999999'],
        ]);
    }

    private function canonical(array $lines): array
    {
        $method = new ReflectionMethod(TechnicalAcceptanceQuantityService::class, 'canonicalLines');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $result */
        $result = $method->invoke(new TechnicalAcceptanceQuantityService(), $lines);

        return $result;
    }

    public function test_boolean_work_id_is_rejected(): void
    {
        $this->expectException(BusinessLogicException::class);
        $this->canonical([
            ['completed_work_id' => true, 'unit_id' => 4, 'presented_quantity' => '1', 'accepted_quantity' => '1', 'defect_quantity' => '0'],
        ]);
    }
}
