<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\Models\CommercialOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AssistantRevenueAllocationStorageTest extends TestCase
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
        Schema::create('commercial_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('RUB');
            $table->string('status')->default('pending_payment');
        });
        $this->migration()->up();
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

    public function test_snapshot_is_nullable_hidden_and_not_client_mass_assignable(): void
    {
        $legacyId = DB::table('commercial_orders')->insertGetId(['amount_minor' => 100000]);
        self::assertNull(CommercialOrder::query()->findOrFail($legacyId)->assistant_revenue_allocation);
        $snapshot = ['version' => 1, 'basis' => 'declared_internal_allocation', 'allocated_amount_minor' => 399000];
        $id = DB::table('commercial_orders')->insertGetId([
            'amount_minor' => 3990000,
            'assistant_revenue_allocation' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        $order = CommercialOrder::query()->findOrFail($id);
        $actualSnapshot = $order->assistant_revenue_allocation;
        ksort($snapshot);
        ksort($actualSnapshot);
        self::assertSame($snapshot, $actualSnapshot);
        self::assertFalse($order->isFillable('assistant_revenue_allocation'));
        self::assertArrayNotHasKey('assistant_revenue_allocation', $order->toArray());
    }

    public function test_snapshot_is_immutable_while_order_status_can_change(): void
    {
        $snapshot = ['version' => 1, 'allocated_amount_minor' => 399000];
        $id = DB::table('commercial_orders')->insertGetId([
            'amount_minor' => 3990000,
            'assistant_revenue_allocation' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        DB::table('commercial_orders')->where('id', $id)->update(['status' => 'paid']);
        foreach ([null, json_encode(['version' => 1, 'allocated_amount_minor' => 1], JSON_THROW_ON_ERROR)] as $replacement) {
            try {
                DB::transaction(fn () => DB::table('commercial_orders')->where('id', $id)->update(['assistant_revenue_allocation' => $replacement]));
                self::fail('A financial provenance snapshot was changed.');
            } catch (QueryException $exception) {
                self::assertSame('23000', $exception->getCode());
            }
        }
        $order = CommercialOrder::query()->findOrFail($id);
        self::assertSame('paid', $order->status->value);
        self::assertSame($snapshot, $order->assistant_revenue_allocation);
    }

    public function test_rollback_preserves_financial_provenance(): void
    {
        DB::table('commercial_orders')->insert([
            'amount_minor' => 3990000,
            'assistant_revenue_allocation' => json_encode(['version' => 1, 'allocated_amount_minor' => 399000], JSON_THROW_ON_ERROR),
        ]);
        try {
            $this->migration()->down();
            self::fail('A populated financial provenance column was removed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Assistant revenue allocations must be preserved.', $exception->getMessage());
        }
        self::assertTrue(Schema::hasColumn('commercial_orders', 'assistant_revenue_allocation'));
        self::assertSame(1, DB::table('commercial_orders')->whereNotNull('assistant_revenue_allocation')->count());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_29_000014_add_assistant_revenue_allocation_to_commercial_orders.php');
    }
}
