<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\FormulaAwarenessService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\SubItemGroupingService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SubItemGroupingServiceTest extends TestCase
{
    private mixed $previousApplication;
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('log', new NullLogger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        Container::setInstance($this->previousContainer);
    }

    public static function numberedRoots(): array
    {
        $cases = [];
        foreach (['position_number', 'section_number'] as $field) {
            foreach (['material', 'machinery', 'labor'] as $type) {
                $cases["{$field}/{$type}"] = [$field, $type];
            }
        }
        return $cases;
    }

    #[DataProvider('numberedRoots')]
    public function test_numbered_dto_roots_keep_their_own_cost_and_parentage(string $field, string $type): void
    {
        $root = (new EstimateImportRowDTO(sectionNumber: '2', itemName: 'Материал', itemType: $type, quantity: 2, unitPrice: 100, currentTotalAmount: 250, overheadAmount: 30, profitAmount: 20, rawData: ['financial_structure' => ['layout' => 'grand_modern_2020']]))->toArray();
        if ($field === 'position_number') {
            $root['position_number'] = $root['section_number'];
            unset($root['section_number']);
        }
        $rows = (new SubItemGroupingService)->groupItems([
            (new EstimateImportRowDTO(sectionNumber: '1', itemName: 'Работа'))->toArray(),
            $root,
            (new EstimateImportRowDTO(itemName: 'Дочерний ресурс', isSubItem: true, itemType: 'material'))->toArray(),
        ]);

        self::assertFalse($rows[1]['is_sub_item']);
        self::assertArrayNotHasKey('_parent_index', $rows[1]);
        self::assertTrue($rows[2]['is_sub_item']);
        self::assertSame(1, $rows[2]['_parent_index']);
        foreach (['quantity', 'unit_price', 'current_total_amount', 'overhead_amount', 'profit_amount', 'raw_data'] as $key) {
            self::assertSame($root[$key], $rows[1][$key]);
        }
        (new FormulaAwarenessService)->annotate($rows);
        self::assertFalse($rows[1]['has_math_mismatch']);
        self::assertSame(250.0, $rows[1]['math_expected_amount']);
    }

    public function test_explicit_child_flag_wins_over_a_numbered_alias(): void
    {
        $rows = (new SubItemGroupingService)->groupItems([
            ['position_number' => '1', 'item_name' => 'Работа'],
            ['section_number' => '2', 'item_name' => 'Ресурс', 'is_sub_item' => true],
        ]);
        self::assertTrue($rows[1]['is_sub_item']);
        self::assertSame(0, $rows[1]['_parent_index']);
    }

    public function test_dotted_positions_and_explicit_position_priority_keep_existing_resource_rules(): void
    {
        foreach ([['section_number' => '1.1'], ['position_number' => '1.1', 'section_number' => '2']] as $identity) {
            $rows = (new SubItemGroupingService)->groupItems([
                ['position_number' => '1', 'item_name' => 'Работа'],
                $identity + ['item_name' => 'Материал', 'item_type' => 'material'],
            ]);
            self::assertTrue($rows[1]['is_sub_item']);
            self::assertSame(0, $rows[1]['_parent_index']);
        }
    }

    public function test_numbered_root_in_the_next_batch_starts_its_own_parentage(): void
    {
        $state = [];
        $service = new SubItemGroupingService;
        $service->groupItems([['section_number' => '1', 'item_name' => 'Работа']], $state);
        $rows = $service->groupItems([
            ['section_number' => '2', 'item_name' => 'Материал', 'item_type' => 'material'],
            ['item_name' => 'Ресурс', 'is_sub_item' => true],
        ], $state);
        self::assertFalse($rows[0]['is_sub_item']);
        self::assertArrayNotHasKey('_parent_index', $rows[0]);
        self::assertSame(0, $rows[1]['_parent_index']);
    }
}
