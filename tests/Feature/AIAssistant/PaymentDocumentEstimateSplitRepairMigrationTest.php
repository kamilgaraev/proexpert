<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class PaymentDocumentEstimateSplitRepairMigrationTest extends TestCase
{
    private ?string $connectionName = null;
    private ?array $originalConfiguration = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConfiguration = config('database.connections.'.$this->connectionName);
        config()->set('database.connections.'.$this->connectionName, IsolatedPostgresTestDatabase::configuration());
        DB::purge($this->connectionName);
        DB::connection($this->connectionName);
    }

    protected function tearDown(): void
    {
        if ($this->connectionName !== null && $this->originalConfiguration !== null) {
            DB::purge($this->connectionName);
            config()->set('database.connections.'.$this->connectionName, $this->originalConfiguration);
            DB::connection($this->connectionName);
        }
        parent::tearDown();
    }

    public function test_repair_restores_missing_columns_without_changing_existing_rows_or_rollback_values(): void
    {
        $this->createBaseTable();
        $id = DB::table('payment_document_estimate_splits')->insertGetId([
            'payment_document_id' => 17,
            'estimate_item_id' => 29,
            'amount' => '500.00',
            'percentage' => '25.00',
        ]);

        $migration = $this->migration();
        $migration->up();
        $this->assertPriceColumnTypes();

        $row = DB::table('payment_document_estimate_splits')->find($id);
        self::assertSame(17, (int) $row->payment_document_id);
        self::assertSame(29, (int) $row->estimate_item_id);
        self::assertSame(500.0, (float) $row->amount);
        self::assertSame(25.0, (float) $row->percentage);
        foreach (['quantity', 'unit_price_plan', 'unit_price_actual', 'price_deviation'] as $column) {
            self::assertNull($row->{$column});
        }

        DB::table('payment_document_estimate_splits')->where('id', $id)->update([
            'quantity' => '1.25000000',
            'unit_price_plan' => '125.0000',
            'unit_price_actual' => '130.0000',
            'price_deviation' => '6.25',
        ]);
        $migration->up();
        $migration->down();
        $row = DB::table('payment_document_estimate_splits')->find($id);
        self::assertSame(1.25, (float) $row->quantity);
        self::assertSame(125.0, (float) $row->unit_price_plan);
        self::assertSame(130.0, (float) $row->unit_price_actual);
        self::assertSame(6.25, (float) $row->price_deviation);
        $this->assertPriceColumnTypes();
    }

    public function test_repair_adds_only_missing_columns_in_partially_repaired_schema(): void
    {
        $this->createBaseTable();
        Schema::table('payment_document_estimate_splits', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 8)->nullable();
            $table->decimal('unit_price_actual', 12, 4)->nullable();
        });
        $id = DB::table('payment_document_estimate_splits')->insertGetId([
            'payment_document_id' => 19,
            'estimate_item_id' => 31,
            'amount' => '750.00',
            'percentage' => '50.00',
            'quantity' => '2.50000000',
            'unit_price_actual' => '300.0000',
        ]);

        $this->migration()->up();
        $row = DB::table('payment_document_estimate_splits')->find($id);
        self::assertSame(2.5, (float) $row->quantity);
        self::assertSame(300.0, (float) $row->unit_price_actual);
        self::assertNull($row->unit_price_plan);
        self::assertNull($row->price_deviation);
        $this->assertPriceColumnTypes();
    }

    private function createBaseTable(): void
    {
        Schema::create('payment_document_estimate_splits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_document_id');
            $table->unsignedBigInteger('estimate_item_id');
            $table->decimal('amount', 15, 2);
            $table->decimal('percentage', 5, 2);
            $table->timestamps();
        });
    }

    private function assertPriceColumnTypes(): void
    {
        $schema = DB::selectOne('select current_schema() as name')->name;
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', 'payment_document_estimate_splits')
            ->whereIn('column_name', ['quantity', 'unit_price_plan', 'unit_price_actual', 'price_deviation'])
            ->get()
            ->keyBy('column_name');

        foreach (['quantity' => [12, 8], 'unit_price_plan' => [12, 4], 'unit_price_actual' => [12, 4], 'price_deviation' => [14, 2]] as $name => [$precision, $scale]) {
            self::assertTrue($columns->has($name), $name);
            self::assertSame($precision, (int) $columns[$name]->numeric_precision);
            self::assertSame($scale, (int) $columns[$name]->numeric_scale);
            self::assertSame('YES', $columns[$name]->is_nullable);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_30_000001_restore_payment_document_estimate_split_price_columns.php');
    }
}
