<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\WarehouseInventoryEventRecorder;
use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnAuthorizer;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Reporting\ProcurementReportingLifecycleRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyLifecycleEventRecorder;
use App\BusinessModules\Features\Procurement\Services\DatabasePurchaseReceiptReturnUnitOfWork;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderService;
use App\BusinessModules\Features\Procurement\Services\PurchaseReceiptInventoryService;
use App\Models\User;
use DomainException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;
use Throwable;

final class SupplyReturnLaravelPostgresIntegrationTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $first;

    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('SUPPLY_REPORTS_PG_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set SUPPLY_REPORTS_PG_DSN to run the real PostgreSQL return fixture.');
        }

        $this->database = new Capsule;
        $config = $this->connectionConfig($dsn);
        $this->database->addConnection($config, 'supply_first');
        $this->database->addConnection($config, 'supply_second');
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $container->instance('cache', new class
        {
            public function forget(string $key): bool
            {
                return true;
            }
        });
        $container->instance('translator', new class
        {
            public function get(string $key, array $replace = [], ?string $locale = null): string
            {
                return $key;
            }
        });
        $container->instance('log', new class
        {
            public function warning(string $message): void {}
        });
        Facade::setFacadeApplication($container);

        $this->first = $this->database->getConnection('supply_first');
        $this->schema = 'supply_return_'.bin2hex(random_bytes(8));
        $this->first->statement("CREATE SCHEMA {$this->schema}");
        $this->first->statement("SET search_path TO {$this->schema}");
        $this->installSchema();
        $this->seedGraph();
        $this->database->getDatabaseManager()->setDefaultConnection('supply_first');
    }

    protected function tearDown(): void
    {
        if (isset($this->first, $this->schema)) {
            $this->first->statement('SET search_path TO public');
            $this->first->statement("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_success_replay_payload_conflict_and_atomic_failure_use_the_real_return_uow(): void
    {
        $service = $this->service();
        $actor = $this->actor();
        self::assertSame(1, $this->rowCount('sent_purchase_order_line_owners'));
        self::assertSame(1, $this->rowCount('purchase_order_promise_versions'));
        self::assertSame(1, $this->rowCount('purchase_receipts'));
        self::assertSame(1, $this->rowCount('purchase_receipt_inventory_lots'));
        self::assertSame(1, $this->rowCount('warehouse_balances'));

        $service->returnReceiptLine(19, 20, 40, '2', 'damaged', 'return-key-0001', $actor);
        $service->returnReceiptLine(19, 20, 40, '2', 'damaged', 'return-key-0001', $actor);

        self::assertSame('8.000', $this->decimal('warehouse_balances', 'available_quantity'));
        self::assertSame('2.000000', $this->decimal('purchase_receipt_inventory_lots', 'returned_quantity'));
        self::assertSame(1, $this->rowCount('purchase_receipt_returns'));
        self::assertSame(1, $this->rowCount('warehouse_movements', "operation_category = 'procurement_receipt_return'"));
        self::assertSame(1, $this->rowCount('warehouse_inventory_events'));
        self::assertSame(1, $this->rowCount('supply_lifecycle_events', "event_type = 'returned'"));

        try {
            $service->returnReceiptLine(19, 20, 40, '3', 'damaged', 'return-key-0001', $actor);
            self::fail('Changed payload reused a committed idempotency key.');
        } catch (DomainException $exception) {
            self::assertSame('procurement.purchase_orders.receipt_return_idempotency_conflict', $exception->getMessage());
        }

        $this->first->statement(<<<'SQL'
CREATE FUNCTION reject_return_fixture() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.idempotency_key = 'return-key-failure' THEN
        RAISE EXCEPTION 'injected return failure';
    END IF;
    RETURN NEW;
END
$$
SQL);
        $this->first->statement(
            'CREATE TRIGGER reject_return_fixture BEFORE INSERT ON purchase_receipt_returns '
            .'FOR EACH ROW EXECUTE FUNCTION reject_return_fixture()',
        );
        try {
            $service->returnReceiptLine(19, 20, 40, '1', 'damaged', 'return-key-failure', $actor);
            self::fail('Injected persistence failure was not propagated.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('injected return failure', $exception->getMessage());
        }

        self::assertSame('8.000', $this->decimal('warehouse_balances', 'available_quantity'));
        self::assertSame('2.000000', $this->decimal('purchase_receipt_inventory_lots', 'returned_quantity'));
        self::assertSame(1, $this->rowCount('purchase_receipt_returns'));
        self::assertSame(1, $this->rowCount('warehouse_movements', "operation_category = 'procurement_receipt_return'"));
        self::assertSame(1, $this->rowCount('warehouse_inventory_events'));
        self::assertSame(1, $this->rowCount('supply_lifecycle_events', "event_type = 'returned'"));
    }

    public function test_same_key_is_serialized_across_two_real_connections(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The two-connection serialization fixture requires pcntl.');
        }

        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $manager = $this->database->getDatabaseManager();
                $manager->disconnect('supply_first');
                $manager->disconnect('supply_second');
                $name = $worker === 0 ? 'supply_first' : 'supply_second';
                $manager->setDefaultConnection($name);
                $manager->connection($name)->statement("SET search_path TO {$this->schema}");
                try {
                    $this->service()->returnReceiptLine(
                        19,
                        20,
                        40,
                        '2',
                        'damaged',
                        'return-key-race',
                        $this->actor(),
                    );
                    exit(0);
                } catch (Throwable) {
                    exit(21);
                }
            }
            if ($pid < 0) {
                throw new RuntimeException('supply_return_fixture_fork_failed');
            }
            $children[] = $pid;
        }

        $outcomes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            $outcomes[] = pcntl_wexitstatus($status);
        }

        self::assertSame([0, 0], $outcomes);
        self::assertSame(1, $this->rowCount('purchase_receipt_returns'));
        self::assertSame('8.000', $this->decimal('warehouse_balances', 'available_quantity'));
        self::assertSame('2.000000', $this->decimal('purchase_receipt_inventory_lots', 'returned_quantity'));
        self::assertSame(1, $this->rowCount('warehouse_movements', "operation_category = 'procurement_receipt_return'"));
        self::assertSame(1, $this->rowCount('warehouse_inventory_events'));
        self::assertSame(1, $this->rowCount('supply_lifecycle_events', "event_type = 'returned'"));
    }

    private function service(): PurchaseOrderService
    {
        $authorizer = new class implements PurchaseReceiptReturnAuthorizer
        {
            public function canReturn(
                User $actor,
                int $organizationId,
                int $purchaseOrderId,
                int $lineId,
            ): bool {
                return true;
            }

            public function assertCanReturn(
                User $actor,
                int $organizationId,
                int $purchaseOrderId,
                int $lineId,
            ): PurchaseReceiptLine {
                return PurchaseReceiptLine::query()
                    ->whereKey($lineId)
                    ->whereHas('purchaseReceipt', static fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->where('purchase_order_id', $purchaseOrderId))
                    ->firstOrFail();
            }
        };
        $reporting = $this->instanceWithoutConstructor(ProcurementReportingLifecycleRecorder::class);
        $this->setProperty($reporting, 'supplyEvents', new SupplyLifecycleEventRecorder);
        foreach (['processEvents', 'promiseVersions', 'sentLineOwners', 'businessTimezone'] as $property) {
            $this->setPropertyFromConstructorType($reporting, $property);
        }
        $service = $this->instanceWithoutConstructor(PurchaseOrderService::class);
        foreach ((new ReflectionClass(PurchaseOrderService::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $value = match ($parameter->getName()) {
                'reportingLifecycle' => $reporting,
                'receiptInventory' => new PurchaseReceiptInventoryService(new WarehouseInventoryEventRecorder),
                'returnAuthorizer' => $authorizer,
                'returnUnitOfWork' => new DatabasePurchaseReceiptReturnUnitOfWork,
                default => $this->dummyFor($parameter),
            };
            $this->setProperty($service, $parameter->getName(), $value);
        }

        return $service;
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 7]);
        $actor->exists = true;

        return $actor;
    }

    private function instanceWithoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function setPropertyFromConstructorType(object $object, string $property): void
    {
        $constructor = (new ReflectionClass($object))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property) {
                $this->setProperty($object, $property, $this->dummyFor($parameter));

                return;
            }
        }
        throw new RuntimeException("Missing constructor property {$property}.");
    }

    private function dummyFor(ReflectionParameter $parameter): object
    {
        $type = $parameter->getType();
        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            throw new RuntimeException("Cannot construct dependency {$parameter->getName()}.");
        }

        return $this->instanceWithoutConstructor($type->getName());
    }

    private function setProperty(object $object, string $property, object $value): void
    {
        $reflection = new ReflectionClass($object);
        $target = $reflection->getProperty($property);
        $target->setValue($object, $value);
    }

    private function rowCount(string $table, ?string $where = null): int
    {
        $sql = "SELECT count(*) FROM {$table}".($where === null ? '' : " WHERE {$where}");

        return (int) $this->first->scalar($sql);
    }

    private function decimal(string $table, string $column): string
    {
        return (string) $this->first->scalar("SELECT {$column}::text FROM {$table} LIMIT 1");
    }

    private function connectionConfig(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^pgsql:/', '', $dsn) ?? '') as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $parts[$key] = $value;
            }
        }

        return [
            'driver' => 'pgsql',
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? '5432',
            'database' => $parts['dbname'] ?? '',
            'username' => (string) getenv('SUPPLY_REPORTS_PG_USER'),
            'password' => (string) getenv('SUPPLY_REPORTS_PG_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ];
    }

    private function installSchema(): void
    {
        $this->first->unprepared(<<<'SQL'
CREATE TABLE purchase_orders (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_request_id bigint,
 order_number text NOT NULL, status text NOT NULL, total_amount numeric(18,2) NOT NULL DEFAULT 0,
 currency text NOT NULL DEFAULT 'RUB', pricing_source text NOT NULL DEFAULT 'fixture',
 supplier_snapshot jsonb NOT NULL DEFAULT '{}', metadata jsonb NOT NULL DEFAULT '{}',
 order_date date, delivery_date date, sent_at timestamptz, confirmed_at timestamptz,
 cancelled_at timestamptz, deleted_at timestamptz, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE purchase_order_items (
 id bigserial PRIMARY KEY, purchase_order_id bigint NOT NULL, material_id bigint NOT NULL,
 quantity numeric(24,6) NOT NULL, unit text NOT NULL, price numeric(18,2) NOT NULL,
 total_amount numeric(18,2) NOT NULL, metadata jsonb NOT NULL DEFAULT '{}',
 created_at timestamptz, updated_at timestamptz
);
CREATE TABLE purchase_receipts (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_order_id bigint NOT NULL,
 warehouse_id bigint NOT NULL, received_by_user_id bigint, receipt_number text NOT NULL,
 receipt_date date NOT NULL, status text NOT NULL, notes text, metadata jsonb NOT NULL DEFAULT '{}',
 deleted_at timestamptz, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE purchase_receipt_lines (
 id bigserial PRIMARY KEY, purchase_receipt_id bigint NOT NULL, purchase_order_item_id bigint NOT NULL,
 quantity_received numeric(24,3) NOT NULL, price numeric(18,2) NOT NULL,
 total_amount numeric(18,2) NOT NULL, metadata jsonb NOT NULL DEFAULT '{}',
 reversed_at timestamptz, reversed_by_user_id bigint, reversal_reason_code text,
 reversal_warehouse_movement_id bigint, reversal_idempotency_key text,
 created_at timestamptz, updated_at timestamptz
);
CREATE TABLE warehouse_balances (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, warehouse_id bigint NOT NULL,
 cell_id bigint, material_id bigint NOT NULL, available_quantity numeric(24,3) NOT NULL,
 reserved_quantity numeric(24,3) NOT NULL DEFAULT 0, unit_price numeric(18,2),
 batch_number text, last_movement_at timestamptz, created_at timestamptz
);
CREATE TABLE warehouse_movements (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, warehouse_id bigint NOT NULL,
 cell_id bigint, material_id bigint NOT NULL, movement_type text NOT NULL,
 quantity numeric(24,3) NOT NULL, price numeric(18,2), from_warehouse_id bigint,
 to_warehouse_id bigint, project_id bigint, project_material_delivery_id bigint,
 user_id bigint, related_user_id bigint, document_number text, reason text,
 operation_category text, metadata jsonb NOT NULL DEFAULT '{}', movement_date timestamptz,
 created_at timestamptz, updated_at timestamptz
);
CREATE TABLE purchase_receipt_inventory_lots (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_receipt_line_id bigint NOT NULL,
 warehouse_balance_id bigint NOT NULL, receipt_warehouse_movement_id bigint NOT NULL,
 original_quantity numeric(24,6) NOT NULL, reversed_quantity numeric(24,6) NOT NULL DEFAULT 0,
 returned_quantity numeric(24,6) NOT NULL DEFAULT 0, unit_dimension text NOT NULL,
 unit_code text NOT NULL, conversion_version text NOT NULL, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE sent_purchase_order_line_owners (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_order_id bigint NOT NULL,
 purchase_order_item_id bigint NOT NULL, project_id bigint NOT NULL, warehouse_id bigint NOT NULL,
 material_id bigint NOT NULL, source_hash char(64) NOT NULL
);
CREATE TABLE purchase_order_promise_versions (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_order_id bigint NOT NULL,
 purchase_order_item_id bigint NOT NULL, promise_version integer NOT NULL, supplier_id bigint NOT NULL,
 project_id bigint NOT NULL, warehouse_id bigint NOT NULL, material_id bigint NOT NULL,
 buyer_id bigint NOT NULL, promised_at timestamptz NOT NULL, effective_from timestamptz NOT NULL,
 effective_to timestamptz, source_version integer NOT NULL, ordered_quantity numeric(24,6) NOT NULL,
 ordered_value_minor bigint NOT NULL, unit_dimension text NOT NULL, unit_code text NOT NULL,
 conversion_version text NOT NULL, currency text NOT NULL, source_hash char(64) NOT NULL,
 recorded_at timestamptz NOT NULL
);
CREATE TABLE supply_lifecycle_events (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_order_id bigint NOT NULL,
 purchase_order_item_id bigint NOT NULL, promise_version_id bigint NOT NULL, event_type text NOT NULL,
 source_type text NOT NULL, source_id bigint NOT NULL, source_version integer NOT NULL,
 signed_quantity numeric(24,6) NOT NULL, unit_dimension text NOT NULL, unit_code text NOT NULL,
 conversion_version text NOT NULL, occurred_at timestamptz NOT NULL, reversed_event_id bigint,
 reason_code text, idempotency_key text NOT NULL, evidence jsonb NOT NULL,
 source_hash char(64) NOT NULL, recorded_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (organization_id, idempotency_key)
);
CREATE TABLE warehouse_inventory_events (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, warehouse_id bigint NOT NULL,
 project_id bigint, material_id bigint NOT NULL, source_movement_id bigint NOT NULL,
 source_version integer NOT NULL, event_type text NOT NULL, on_hand_delta numeric(24,6) NOT NULL,
 reserved_delta numeric(24,6) NOT NULL, transfer_pair_key text, unit_dimension text NOT NULL,
 unit_code text NOT NULL, conversion_version text NOT NULL, unit_price_minor bigint,
 currency text, currency_source text, occurred_at timestamptz NOT NULL, opening_basis text,
 source_refs jsonb NOT NULL, source_hash char(64) NOT NULL, recorded_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE (organization_id, source_movement_id, source_version, event_type)
);
CREATE TABLE purchase_receipt_returns (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, purchase_receipt_line_id bigint NOT NULL,
 warehouse_movement_id bigint NOT NULL, supply_lifecycle_event_id bigint NOT NULL,
 source_type text NOT NULL, source_id bigint NOT NULL, source_version integer NOT NULL,
 quantity numeric(24,6) NOT NULL, reason_code text NOT NULL, actor_id bigint NOT NULL,
 occurred_at timestamptz NOT NULL, idempotency_key text NOT NULL, payload_fingerprint char(64) NOT NULL,
 created_at timestamptz, updated_at timestamptz, UNIQUE (organization_id, idempotency_key)
);
SQL);
    }

    private function seedGraph(): void
    {
        $this->first->unprepared(<<<'SQL'
INSERT INTO purchase_orders
 (id, organization_id, order_number, status, total_amount, order_date, created_at, updated_at)
VALUES (20, 19, 'PO-20', 'confirmed', 1000, '2026-07-30', now(), now());
INSERT INTO purchase_order_items
 (id, purchase_order_id, material_id, quantity, unit, price, total_amount, metadata, created_at, updated_at)
VALUES (10, 20, 501, 10, 'pcs', 100, 1000, '{"reporting_source_version":1}', now(), now());
INSERT INTO purchase_receipts
 (id, organization_id, purchase_order_id, warehouse_id, receipt_number, receipt_date, status, created_at, updated_at)
VALUES (30, 19, 20, 601, 'RCPT-30', '2026-07-30', 'posted', now(), now());
INSERT INTO purchase_receipt_lines
 (id, purchase_receipt_id, purchase_order_item_id, quantity_received, price, total_amount, metadata, created_at, updated_at)
VALUES (40, 30, 10, 10, 100, 1000, '{"reporting_source_version":1}', now(), now());
INSERT INTO warehouse_balances
 (id, organization_id, warehouse_id, material_id, available_quantity, unit_price, batch_number, created_at)
VALUES (50, 19, 601, 501, 10, 100, 'purchase-receipt-line:40', now());
INSERT INTO warehouse_movements
 (id, organization_id, warehouse_id, material_id, movement_type, quantity, price, project_id,
  user_id, document_number, operation_category, metadata, movement_date, created_at, updated_at)
VALUES (60, 19, 601, 501, 'receipt', 10, 100, 701, 7, 'RCPT-30', 'procurement_receipt',
 '{"reporting_source_version":1,"unit_dimension":"count","unit_code":"pcs","unit_conversion_version":"count-v1","reporting_inventory_project_id":701,"currency":"RUB","currency_source":"purchase_order"}',
 '2026-07-30 08:00:00+00', now(), now());
INSERT INTO purchase_receipt_inventory_lots
 (id, organization_id, purchase_receipt_line_id, warehouse_balance_id, receipt_warehouse_movement_id,
  original_quantity, reversed_quantity, returned_quantity, unit_dimension, unit_code, conversion_version,
  created_at, updated_at)
VALUES (70, 19, 40, 50, 60, 10, 0, 0, 'count', 'pcs', 'count-v1', now(), now());
INSERT INTO sent_purchase_order_line_owners
 (organization_id, purchase_order_id, purchase_order_item_id, project_id, warehouse_id, material_id, source_hash)
VALUES (19, 20, 10, 701, 601, 501, repeat('a', 64));
INSERT INTO purchase_order_promise_versions
 (id, organization_id, purchase_order_id, purchase_order_item_id, promise_version, supplier_id, project_id,
  warehouse_id, material_id, buyer_id, promised_at, effective_from, source_version, ordered_quantity,
  ordered_value_minor, unit_dimension, unit_code, conversion_version, currency, source_hash, recorded_at)
VALUES (80, 19, 20, 10, 1, 801, 701, 601, 501, 7, '2026-08-01 00:00:00+00',
 '2026-07-30 08:00:00+00', 1, 10, 100000, 'count', 'pcs', 'count-v1', 'RUB', repeat('b', 64), now());
SQL);
    }
}
