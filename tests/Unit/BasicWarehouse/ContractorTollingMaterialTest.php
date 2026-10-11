<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\DTO\ContractorMaterialTransferRow;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Queries\ContractorMaterialTransferQuery;
use App\BusinessModules\Features\BasicWarehouse\Services\ContractorMaterialTransferReportService;
use App\BusinessModules\Features\BasicWarehouse\Services\ContractorTransferDocument;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseMovementFormExportService;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseMovementDocumentResolver;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M4\M4ExportStrategy;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

final class ContractorTollingMaterialTest extends TestCase
{
    private $previousResolver;
    private ?Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('app', Mockery::mock()->shouldReceive('getLocale')->andReturn('ru')->getMock());
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $loader = new ArrayLoader;
        $loader->addMessages('ru', 'warehouse_basic', require __DIR__.'/../../../lang/ru/warehouse_basic.php');
        $container->instance('translator', new Translator($loader, 'ru'));
        $container->instance('log', new NullLogger);

        $connection = new class(static fn () => throw new RuntimeException('Test must not connect to a database.'), 'unit') extends PostgresConnection {
            protected function escapeString($value): string
            {
                return "'".str_replace("'", "''", $value)."'";
            }
        };
        $resolver = new ConnectionResolver(['unit' => $connection]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->previousContainer);
        Mockery::close();
        parent::tearDown();
    }

    public static function documentCases(): array
    {
        return [
            'cross organization sender' => ['write_off', ['is_contractor_transfer' => true], null, ['m15']],
            'external warehouse sender' => ['transfer_out', ['is_contractor_transfer' => true], null, ['m15']],
            'customer material receipt' => ['receipt', ['is_customer_transfer' => true], null, ['m4']],
            'virtual warehouse arrival is not another outgoing transfer' => ['transfer_in', ['is_contractor_transfer' => true], null, []],
            'ordinary receipt retains forms' => ['receipt', [], null, ['m4', 'm7']],
            'responsible issue' => ['transfer_out', [], 'responsible_issue', ['m11']],
            'production usage' => ['write_off', [], 'production_usage', ['m11']],
            'loss is not an external issue' => ['write_off', [], 'loss', []],
            'placement has no external document' => ['transfer_out', [], 'placement', []],
        ];
    }

    #[DataProvider('documentCases')]
    public function test_history_and_export_use_the_same_document_policy(string $type, array $metadata, ?string $category, array $expected): void
    {
        $movement = new WarehouseMovement(['movement_type' => $type, 'metadata' => $metadata, 'operation_category' => $category]);
        self::assertSame($expected, $movement->availableDocumentTypes());
        $service = (new \ReflectionClass(WarehouseMovementFormExportService::class))->newInstanceWithoutConstructor();
        $supports = new ReflectionMethod($service, 'supports');
        foreach (['m4', 'm7', 'm11', 'm15'] as $form) {
            self::assertSame(in_array($form, $expected, true), $supports->invoke($service, $form, $movement));
        }
    }

    public function test_missing_m15_number_is_stable_across_retries_and_scoped_to_organization(): void
    {
        $number = ContractorTransferDocument::number(12, 'request-1', null);
        self::assertSame($number, ContractorTransferDocument::number(12, 'request-1', ''));
        self::assertNotSame($number, ContractorTransferDocument::number(13, 'request-1', null));
        self::assertNotSame($number, ContractorTransferDocument::number(12, 'request-2', null));
        self::assertSame('НК-1', ContractorTransferDocument::number(12, 'request-1', ' НК-1 '));
        self::assertStringStartsWith('М-15-', $number);
    }

    public function test_legacy_transfers_are_visible_and_their_nonzero_valuation_is_preserved(): void
    {
        $movement = new WarehouseMovement([
            'movement_type' => 'write_off', 'metadata' => ['is_contractor_transfer' => true, 'contractor_id' => 7],
            'quantity' => '2.345', 'price' => '12.34', 'movement_date' => '2026-10-11 12:00:00',
        ]);
        $movement->id = 51;
        $material = new Material(['name' => 'Цемент', 'code' => 'CEM']);
        $material->setRelation('measurementUnit', new MeasurementUnit(['short_name' => 'кг']));
        $movement->setRelation('material', $material);
        $movement->setRelation('warehouse', new Organization(['name' => 'Склад']));
        $movement->setRelation('project', null);
        $row = ContractorMaterialTransferRow::fromMovement($movement, 'Подрядчик');
        self::assertSame('М-15-51', $row['document_number']);
        self::assertSame('Подрядчик', $row['contractor_name']);
        self::assertSame(12.34, $row['price']);
        self::assertSame(28.94, $row['total_value']);
        self::assertSame('кг', $row['measurement_unit']);
        self::assertSame('Передача давальческого материала подрядчику', $movement->operationCategoryLabel());
    }

    public function test_report_query_excludes_receipts_and_binds_organization_and_search(): void
    {
        $query = (new ContractorMaterialTransferQuery)->build(12, [
            'search' => "%' OR 1=1 --", 'date_from' => '2026-10-01', 'date_to' => '2026-10-11', 'contractor_id' => 7,
        ]);
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        self::assertStringContainsString('"organization_id" = ?', $sql);
        self::assertStringContainsString('"movement_type" in (?, ?)', $sql);
        self::assertContains('write_off', $bindings);
        self::assertContains('transfer_out', $bindings);
        self::assertNotContains('receipt', $bindings);
        self::assertNotContains('transfer_in', $bindings);
        self::assertSame(12, $bindings[0]);
        self::assertStringNotContainsString("OR 1=1 --", $sql);
        self::assertContains("%\\%' OR 1=1 --%", $bindings);
        self::assertContains('2026-10-11', $bindings);
    }

    public function test_same_document_number_does_not_mix_different_contractors(): void
    {
        $movement = new WarehouseMovement([
            'organization_id' => 12, 'warehouse_id' => 4, 'material_id' => 15,
            'movement_type' => 'write_off', 'document_number' => 'НК-1',
            'metadata' => ['is_contractor_transfer' => true, 'contractor_id' => 7],
            'movement_date' => '2026-10-11 12:00:00',
        ]);
        $statements = Model::getConnectionResolver()->connection()->pretend(static function () use ($movement): void {
            (new WarehouseMovementDocumentResolver)->resolve($movement);
        });
        self::assertCount(1, $statements);
        self::assertStringContainsString("metadata\"->>'contractor_id'", $statements[0]['query']);
        self::assertContains(7, $statements[0]['bindings']);
        self::assertContains(12, $statements[0]['bindings']);
    }

    public function test_report_denies_an_actor_before_reading_movements(): void
    {
        $actor = new User;
        $actor->id = 4;
        $actor->current_organization_id = 12;
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->once()->with($actor, 'warehouse.reports', ['organization_id' => 12])->andReturnFalse();
        $query = Mockery::mock(ContractorMaterialTransferQuery::class);
        $query->shouldNotReceive('build');
        $service = new ContractorMaterialTransferReportService($query, $authorization, Mockery::mock(FileService::class));
        $this->expectException(AuthorizationException::class);
        $service->report($actor, []);
    }

    public function test_export_requires_its_own_permission_before_reading_movements(): void
    {
        $actor = new User;
        $actor->id = 4;
        $actor->current_organization_id = 12;
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->with($actor, 'warehouse.reports', ['organization_id' => 12])->andReturnTrue();
        $authorization->shouldReceive('canCurrent')->with($actor, 'warehouse.reports.export', ['organization_id' => 12])->andReturnFalse();
        $query = Mockery::mock(ContractorMaterialTransferQuery::class);
        $query->shouldNotReceive('build');
        $service = new ContractorMaterialTransferReportService($query, $authorization, Mockery::mock(FileService::class));
        $this->expectException(AuthorizationException::class);
        $service->export($actor, []);
    }

    public function test_csv_preserves_quantities_and_escapes_spreadsheet_formulas(): void
    {
        $service = (new \ReflectionClass(ContractorMaterialTransferReportService::class))->newInstanceWithoutConstructor();
        $content = (new ReflectionMethod($service, 'csv'))->invoke($service, [[
            'contractor_name' => '=HYPERLINK("http://invalid")', 'material_name' => 'Цемент',
            'quantity' => 2.345, 'price' => 12.34, 'total_value' => 28.94,
            'document_number' => 'М-15-51',
        ]]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = explode("\n", trim(substr($content, 3)));
        $row = str_getcsv($lines[1], ';');
        self::assertSame("'=HYPERLINK(\"http://invalid\")", $row[1]);
        self::assertSame('2.345', $row[5]);
        self::assertSame('28.94', $row[7]);
        self::assertSame('М-15-51', $row[10]);
    }

    public function test_customer_m4_identifies_tolling_material_without_zeroing_valuation(): void
    {
        $movement = new WarehouseMovement([
            'movement_type' => 'receipt', 'metadata' => ['is_customer_transfer' => true, 'source_organization_name' => 'Заказчик'],
            'quantity' => '2.345', 'price' => '12.34', 'movement_date' => '2026-10-11 12:00:00',
        ]);
        $material = new Material(['name' => 'Цемент']);
        $material->setRelation('measurementUnit', new MeasurementUnit(['name' => 'кг']));
        $movement->setRelation('material', $material);
        $movement->setRelation('organization', new Organization(['name' => 'Подрядчик']));
        $movement->setRelation('warehouse', new Organization(['name' => 'Склад']));
        $strategy = (new \ReflectionClass(M4ExportStrategy::class))->newInstanceWithoutConstructor();
        $sheet = (new Spreadsheet)->getActiveSheet();
        (new ReflectionMethod($strategy, 'setHeader'))->invoke($strategy, $sheet, $movement);
        (new ReflectionMethod($strategy, 'setTable'))->invoke($strategy, $sheet, collect([$movement]));
        self::assertSame('Поставщик: Заказчик', $sheet->getCell('A14')->getValue());
        self::assertStringContainsString('Давальческие материалы заказчика', $sheet->getCell('A15')->getValue());
        self::assertSame('12,34', $sheet->getCell('H17')->getValue());
    }
}
