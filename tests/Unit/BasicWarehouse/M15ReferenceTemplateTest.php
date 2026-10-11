<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M15\M15ExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseDocumentPersonResolver;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehousePersonIdentityResolver;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

final class M15ReferenceTemplateTest extends TestCase
{
    private ?Container $previousContainer;
    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['unit' => new PostgresConnection(
            static fn () => throw new \RuntimeException('Database access is not allowed in this test.'), 'unit',
        )]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);
        $app = new Application(dirname(__DIR__, 3));
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $loader = new ArrayLoader;
        $loader->addMessages('ru', 'warehouse_basic', require __DIR__.'/../../../lang/ru/warehouse_basic.php');
        $app->instance('translator', new Translator($loader, 'ru'));
        $app->instance('log', new NullLogger);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->previousContainer);
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_uses_both_sides_and_all_fifteen_columns_of_the_reference(): void
    {
        $sheet = $this->strategy()->createWorkbook($this->movement())->getActiveSheet();
        $source = IOFactory::load(resource_path('templates/warehouse/m15.xlsx'))->getActiveSheet();
        foreach (['A1', 'A7', 'G18', 'G19', 'AH18', 'AT18', 'AJ38', 'G40', 'A68', 'A70', 'A72', 'A74'] as $cell) {
            self::assertSame($source->getCell($cell)->getValue(), $sheet->getCell($cell)->getValue());
        }
        self::assertSame($source->getMergeCells(), $sheet->getMergeCells());
        self::assertSame($source->getColumnDimension('A')->getWidth(), $sheet->getColumnDimension('A')->getWidth());
        self::assertSame('0315007', $sheet->getCell('AR8')->getValue());
        self::assertSame('00123456', $sheet->getCell('AR9')->getValue());
        self::assertSame('ООО «Заказчик»', $sheet->getCell('F9')->getValue());
        self::assertSame('ООО «Монтаж»', $sheet->getCell('C16')->getValue());
        self::assertStringContainsString('М-15-51', $sheet->getCell('A6')->getValue());
        self::assertStringContainsString('Давальческие материалы', $sheet->getCell('E15')->getValue());
        self::assertSame('11.10.2026', Date::excelToDateTimeObject($sheet->getCell('F13')->getValue())->format('d.m.Y'));
        self::assertSame('11.10.2026', $sheet->getCell('F13')->getFormattedValue());
        self::assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        self::assertSame('A1:AW75', $sheet->getPageSetup()->getPrintArea());
        self::assertArrayHasKey('A36', $sheet->getBreaks());
        self::assertSame('', $sheet->getHeaderFooter()->getOddFooter());
    }

    public function test_fills_numeric_values_with_exact_line_rounding_and_totals_in_words(): void
    {
        $sheet = $this->strategy()->createWorkbook($this->movement())->getActiveSheet();
        self::assertSame('Цемент М500', $sheet->getCell('G21')->getValue());
        self::assertSame('00025', $sheet->getCell('M21')->getValue());
        self::assertSame('кг', $sheet->getCell('R21')->getValue());
        self::assertSame(2.345, $sheet->getCell('V21')->getValue());
        self::assertSame('2.345', $sheet->getCell('V21')->getFormattedValue());
        self::assertSame(2.345, $sheet->getCell('Y21')->getValue());
        self::assertSame(12.34, $sheet->getCell('AB21')->getValue());
        self::assertSame(28.94, $sheet->getCell('AE21')->getValue());
        self::assertSame(28.94, $sheet->getCell('AK21')->getValue());
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('AB21')->getDataType());
        self::assertSame('Одно', $sheet->getCell('G64')->getValue());
        self::assertSame('Двадцать восемь', $sheet->getCell('E66')->getValue());
        self::assertSame('94', $sheet->getCell('AD66')->getValue());
    }

    public function test_preserves_literal_user_text_and_leading_zero_identifiers(): void
    {
        $movement = $this->movement();
        $movement->material->name = '=HYPERLINK("https://invalid")';
        $sheet = $this->strategy()->createWorkbook($movement)->getActiveSheet();
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('G21')->getDataType());
        self::assertSame('=HYPERLINK("https://invalid")', $sheet->getCell('G21')->getValue());
        self::assertSame('@', $sheet->getStyle('M21')->getNumberFormat()->getFormatCode());
    }

    public function test_zero_is_preserved_and_missing_price_is_not_filled_with_zero(): void
    {
        $zero = $this->movement();
        $zero->price = 0;
        $sheet = $this->strategy()->createWorkbook($zero)->getActiveSheet();
        self::assertSame(0.0, $sheet->getCell('AB21')->getValue());
        self::assertSame('Ноль', $sheet->getCell('E66')->getValue());
        $unknown = $this->movement();
        $unknown->price = null;
        $sheet = $this->strategy()->createWorkbook($unknown)->getActiveSheet();
        self::assertNull($sheet->getCell('AB21')->getValue());
        self::assertSame('', $sheet->getCell('E66')->getValue());
        self::assertSame('', $sheet->getCell('AD66')->getValue());
    }

    public function test_continuation_keeps_every_material_and_moves_totals_and_signatures(): void
    {
        $movements = collect();
        for ($index = 1; $index <= 57; $index++) {
            $movements->push($this->movement($index));
        }
        $sheet = $this->strategy()->createWorkbook($movements)->getActiveSheet();
        foreach ([1 => 21, 16 => 36, 17 => 43, 36 => 62, 37 => 68, 56 => 87, 57 => 93] as $id => $row) {
            self::assertSame('000'.(24 + $id), $sheet->getCell('M'.$row)->getValue());
        }
        self::assertSame('Материальные ценности ', $sheet->getCell('G65')->getValue());
        self::assertSame('Материальные ценности ', $sheet->getCell('G90')->getValue());
        self::assertSame('Всего отпущено ', $sheet->getCell('A114')->getValue());
        self::assertSame('Пятьдесят семь', $sheet->getCell('G114')->getValue());
        self::assertSame('Отпустил', $sheet->getCell('A122')->getValue());
        self::assertSame('A1:AW125', $sheet->getPageSetup()->getPrintArea());
        self::assertArrayHasKey('A62', $sheet->getBreaks());
        self::assertArrayHasKey('A87', $sheet->getBreaks());
    }

    public function test_saved_export_keeps_the_form_and_uses_organization_storage(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->withArgs(static function (string $path, string $content): bool {
            return str_starts_with($path, 'org-12/exports/warehouse/m15/')
                && str_starts_with($content, 'PK');
        })->andReturnTrue();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('disk')->once()->andReturn($disk);
        self::assertStringStartsWith('org-12/exports/warehouse/m15/', $this->strategy($files)->export($this->movement()));
    }

    public function test_rejects_an_export_that_mixes_organizations(): void
    {
        $foreign = $this->movement(2);
        $foreign->organization_id = 13;
        $this->expectException(InvalidArgumentException::class);
        $this->strategy()->createWorkbook(collect([$this->movement(), $foreign]));
    }

    public static function movement(int $materialId = 1): WarehouseMovement
    {
        $organization = new Organization(['legal_name' => 'ООО «Заказчик»', 'okpo' => '00123456']);
        $organization->id = 12;
        $material = new Material(['name' => 'Цемент М500', 'code' => '000'.(24 + $materialId), 'accounting_account' => '10.07']);
        $material->setRelation('measurementUnit', new MeasurementUnit(['name' => 'Килограмм', 'short_name' => 'кг']));
        $movement = new WarehouseMovement([
            'organization_id' => 12, 'warehouse_id' => 7, 'material_id' => $materialId,
            'movement_type' => 'write_off', 'quantity' => '2.345', 'price' => '12.34',
            'metadata' => ['is_contractor_transfer' => true, 'contractor_id' => 7, 'contractor_name' => 'ООО «Монтаж»'],
            'movement_date' => '2026-10-11 12:00:00', 'reason' => 'Для монтажа',
        ]);
        $movement->id = 51;
        $movement->setRelation('organization', $organization);
        $movement->setRelation('material', $material);
        $movement->setRelation('warehouse', new OrganizationWarehouse(['name' => 'Главный склад']));
        $movement->setRelation('toWarehouse', null);
        $movement->setRelation('user', null);

        return $movement;
    }

    public static function strategy(?FileService $files = null): M15ExportStrategy
    {
        $identity = (new ReflectionClass(WarehousePersonIdentityResolver::class))->newInstanceWithoutConstructor();

        return new M15ExportStrategy($files ?? Mockery::mock(FileService::class), new WarehouseDocumentPersonResolver($identity));
    }
}
