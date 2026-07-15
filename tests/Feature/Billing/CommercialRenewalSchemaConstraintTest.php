<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CommercialRenewalSchemaConstraintTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
        foreach (['constraint_payments', 'constraint_cycles', 'constraint_orders', 'constraint_accounts'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::statement('CREATE TABLE constraint_accounts (id INTEGER NOT NULL, organization_id INTEGER NOT NULL, PRIMARY KEY (id), UNIQUE (id, organization_id))');
        DB::statement('CREATE TABLE constraint_orders (id INTEGER NOT NULL, commercial_account_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, PRIMARY KEY (id), UNIQUE (id, commercial_account_id, organization_id), FOREIGN KEY (commercial_account_id, organization_id) REFERENCES constraint_accounts (id, organization_id))');
        DB::statement('CREATE TABLE constraint_cycles (id INTEGER NOT NULL, commercial_order_id INTEGER NOT NULL UNIQUE, commercial_account_id INTEGER NOT NULL, organization_id INTEGER NOT NULL, PRIMARY KEY (id), UNIQUE (id, commercial_order_id), FOREIGN KEY (commercial_order_id, commercial_account_id, organization_id) REFERENCES constraint_orders (id, commercial_account_id, organization_id))');
        DB::statement("CREATE TABLE constraint_payments (id INTEGER PRIMARY KEY, commercial_order_id INTEGER NOT NULL, commercial_renewal_cycle_id INTEGER NULL, role TEXT NOT NULL, CHECK ((role = 'initial' AND commercial_renewal_cycle_id IS NULL) OR (role = 'renewal' AND commercial_renewal_cycle_id IS NOT NULL)), FOREIGN KEY (commercial_renewal_cycle_id, commercial_order_id) REFERENCES constraint_cycles (id, commercial_order_id))");
        DB::table('constraint_accounts')->insert([['id' => 1, 'organization_id' => 10], ['id' => 2, 'organization_id' => 20]]);
        DB::table('constraint_orders')->insert([['id' => 100, 'commercial_account_id' => 1, 'organization_id' => 10], ['id' => 200, 'commercial_account_id' => 2, 'organization_id' => 20]]);
        DB::table('constraint_cycles')->insert(['id' => 1000, 'commercial_order_id' => 100, 'commercial_account_id' => 1, 'organization_id' => 10]);
    }

    public function test_order_can_have_only_one_renewal_cycle(): void
    {
        $this->expectException(QueryException::class);
        DB::table('constraint_cycles')->insert(['id' => 1001, 'commercial_order_id' => 100, 'commercial_account_id' => 1, 'organization_id' => 10]);
    }

    public function test_cycle_rejects_mismatched_account_and_organization(): void
    {
        $this->expectException(QueryException::class);
        DB::table('constraint_cycles')->insert(['id' => 2000, 'commercial_order_id' => 200, 'commercial_account_id' => 1, 'organization_id' => 10]);
    }

    public function test_payment_role_requires_matching_cycle_presence(): void
    {
        DB::table('constraint_payments')->insert(['id' => 1, 'commercial_order_id' => 100, 'commercial_renewal_cycle_id' => 1000, 'role' => 'renewal']);

        try {
            DB::table('constraint_payments')->insert(['id' => 2, 'commercial_order_id' => 100, 'commercial_renewal_cycle_id' => 1000, 'role' => 'initial']);
            $this->fail('Initial payment with renewal cycle must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('constraint_payments')->insert(['id' => 3, 'commercial_order_id' => 100, 'commercial_renewal_cycle_id' => null, 'role' => 'renewal']);
    }

    public function test_payment_cycle_and_order_must_match(): void
    {
        $this->expectException(QueryException::class);
        DB::table('constraint_payments')->insert(['id' => 4, 'commercial_order_id' => 200, 'commercial_renewal_cycle_id' => 1000, 'role' => 'renewal']);
    }
}
