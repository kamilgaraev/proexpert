<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class SupplyReturnPostgresNegativeFixtureTest extends TestCase
{
    private PDO $database;

    private string $dsn;

    private string $user;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('SUPPLY_REPORTS_PG_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set SUPPLY_REPORTS_PG_DSN to run PostgreSQL negative fixtures.');
        }
        $this->dsn = $dsn;
        $this->user = (string) getenv('SUPPLY_REPORTS_PG_USER');
        $this->password = (string) getenv('SUPPLY_REPORTS_PG_PASSWORD');
        $this->database = new PDO(
            $this->dsn,
            $this->user,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->database->beginTransaction();
        $this->database->exec('SET LOCAL search_path TO pg_temp');
        $this->installFixtureSchema();
        $this->installProductionTrigger();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->inTransaction()) {
            $this->database->rollBack();
        }
        parent::tearDown();
    }

    public function test_return_rejects_lifecycle_event_from_another_order_item(): void
    {
        $this->seedSource(eventItemId: 99, movementQuantity: '2');

        $this->expectConstraintViolation(fn () => $this->insertReturn());
    }

    public function test_return_rejects_quantity_that_differs_from_source_movement(): void
    {
        $this->seedSource(eventItemId: 10, movementQuantity: '3');

        $this->expectConstraintViolation(fn () => $this->insertReturn());
    }

    private function installFixtureSchema(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TEMP TABLE purchase_receipt_lines (
    id bigint PRIMARY KEY,
    purchase_receipt_id bigint NOT NULL,
    purchase_order_item_id bigint NOT NULL
);
CREATE TEMP TABLE purchase_receipts (
    id bigint PRIMARY KEY,
    organization_id bigint NOT NULL,
    purchase_order_id bigint NOT NULL
);
CREATE TEMP TABLE warehouse_movements (
    id bigint PRIMARY KEY,
    organization_id bigint NOT NULL,
    quantity numeric(24, 6) NOT NULL,
    movement_date timestamptz NOT NULL,
    operation_category text NOT NULL,
    metadata jsonb NOT NULL
);
CREATE TEMP TABLE supply_lifecycle_events (
    id bigint PRIMARY KEY,
    promise_version_id bigint NOT NULL,
    organization_id bigint NOT NULL,
    purchase_order_item_id bigint NOT NULL,
    purchase_order_id bigint NOT NULL,
    source_type text NOT NULL,
    source_id bigint NOT NULL,
    source_version integer NOT NULL,
    event_type text NOT NULL,
    signed_quantity numeric(24, 6) NOT NULL,
    occurred_at timestamptz NOT NULL,
    idempotency_key text NOT NULL
);
CREATE TEMP TABLE purchase_order_items (
    id bigint PRIMARY KEY,
    purchase_order_id bigint NOT NULL
);
CREATE TEMP TABLE purchase_order_promise_versions (
    id bigint PRIMARY KEY,
    organization_id bigint NOT NULL,
    purchase_order_id bigint NOT NULL,
    purchase_order_item_id bigint NOT NULL
);
CREATE TEMP TABLE purchase_receipt_returns (
    organization_id bigint NOT NULL,
    purchase_receipt_line_id bigint NOT NULL,
    warehouse_movement_id bigint NOT NULL,
    supply_lifecycle_event_id bigint NOT NULL,
    source_type text NOT NULL,
    source_id bigint NOT NULL,
    source_version integer NOT NULL,
    quantity numeric(24, 6) NOT NULL,
    occurred_at timestamptz NOT NULL,
    idempotency_key text NOT NULL
);
SQL);
    }

    private function installProductionTrigger(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/Procurement/migrations/'
            .'2026_07_26_120000_create_supply_reliability_reporting_tables.php',
        );
        self::assertIsString($source);
        $start = strpos(
            $source,
            'CREATE OR REPLACE FUNCTION most_purchase_receipt_return_identity_v1()',
        );
        $endMarker = 'FOR EACH ROW EXECUTE FUNCTION most_purchase_receipt_return_identity_v1()';
        $end = strpos($source, $endMarker, $start);
        self::assertIsInt($start);
        self::assertIsInt($end);

        $this->database->exec(substr($source, $start, $end - $start + strlen($endMarker)));
    }

    private function seedSource(int $eventItemId, string $movementQuantity): void
    {
        $timestamp = '2026-07-30T09:00:00+00:00';
        $this->database->exec(
            'INSERT INTO purchase_order_items VALUES (10, 20);'
            .'INSERT INTO purchase_receipts VALUES (30, 19, 20);'
            .'INSERT INTO purchase_receipt_lines VALUES (40, 30, 10);'
            .'INSERT INTO purchase_order_promise_versions VALUES (50, 19, 20, 10);'
            .'INSERT INTO warehouse_movements VALUES '
            ."(60, 19, {$movementQuantity}, '{$timestamp}', 'procurement_receipt_return', "
            ."'{\"returned_purchase_receipt_line_id\":40,\"reporting_source_version\":1}');"
            .'INSERT INTO supply_lifecycle_events VALUES '
            ."(70, 50, 19, {$eventItemId}, 20, 'warehouse_movement', 60, 1, "
            ."'returned', -2, '{$timestamp}', 'return-key-0001');",
        );
    }

    private function insertReturn(): void
    {
        $this->database->exec(
            'INSERT INTO purchase_receipt_returns VALUES '
            ."(19, 40, 60, 70, 'warehouse_movement', 60, 1, 2, "
            ."'2026-07-30T09:00:00+00:00', 'return-key-0001')",
        );
    }

    private function expectConstraintViolation(callable $operation): void
    {
        try {
            $operation();
            self::fail('The production trigger accepted an inconsistent return fixture.');
        } catch (PDOException $exception) {
            self::assertSame('23514', $exception->getCode());
        }
    }
}
