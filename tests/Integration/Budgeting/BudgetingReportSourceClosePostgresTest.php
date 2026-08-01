<?php

declare(strict_types=1);

namespace Tests\Integration\Budgeting;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\TestCase;

final class BudgetingReportSourceClosePostgresTest extends TestCase
{
    private ?Migration $migration = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_PGSQL_CLOSE_CONTRACT_TESTS') !== '1') {
            $this->markTestSkipped('RUN_PGSQL_CLOSE_CONTRACT_TESTS=1 is required.');
        }

        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('pgsql_close_contract_test_connection_required');
        }

        $database = (string) $connection->getDatabaseName();
        if (!str_ends_with($database, '_test')) {
            throw new LogicException('pgsql_close_contract_test_database_required');
        }

        Schema::dropIfExists('budgeting_report_source_watermarks');
        Schema::dropIfExists('budgeting_report_source_closes');

        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->timestamps();
            });
        }

        DB::table('users')->updateOrInsert(
            ['id' => 900000001],
            ['name' => 'Close contract test', 'email' => 'close-contract-test@example.test', 'password' => 'not-used']
        );

        $migration = require dirname(__DIR__, 3) . '/app/BusinessModules/Features/Budgeting/migrations/2026_07_31_120000_create_budgeting_report_source_close_tables.php';
        if (!$migration instanceof Migration) {
            throw new LogicException('pgsql_close_contract_migration_invalid');
        }

        $this->migration = $migration;
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration?->down();

        parent::tearDown();
    }

    public function test_active_identity_is_unique(): void
    {
        DB::table('budgeting_report_source_closes')->insert($this->close('01JZZZZZZZZZZZZZZZZZZZZZZZ'));

        $this->expectException(QueryException::class);
        DB::table('budgeting_report_source_closes')->insert($this->close('01K00000000000000000000000'));
    }

    public function test_header_and_watermark_are_immutable(): void
    {
        $closeId = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
        DB::table('budgeting_report_source_closes')->insert($this->close($closeId));
        DB::table('budgeting_report_source_watermarks')->insert([
            'close_id' => $closeId,
            'source' => 'actuals',
            'cutoff_at' => '2026-01-31 17:00:00+00',
            'watermark' => 'completed_work:771',
            'source_schema_version' => 'actuals-v1',
            'created_at' => '2026-01-31 18:00:00+00',
            'updated_at' => '2026-01-31 18:00:00+00',
        ]);

        try {
            DB::table('budgeting_report_source_closes')->where('close_id', $closeId)->update(['content_hash' => str_repeat('b', 64)]);
            self::fail('The close header update was accepted.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        DB::table('budgeting_report_source_watermarks')->where('close_id', $closeId)->delete();
    }

    public function test_restatement_requires_a_reverse_approved_replacement_with_same_identity(): void
    {
        $priorCloseId = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
        $replacementCloseId = '01K00000000000000000000000';
        DB::table('budgeting_report_source_closes')->insert($this->close($priorCloseId));

        DB::transaction(function () use ($priorCloseId, $replacementCloseId): void {
            DB::table('budgeting_report_source_closes')->where('close_id', $priorCloseId)->update([
                'status' => 'restated',
                'restated_by' => 900000001,
                'restated_at' => '2026-02-01 00:00:00+00',
                'restated_by_close_id' => $replacementCloseId,
            ]);
            DB::table('budgeting_report_source_closes')->insert($this->close($replacementCloseId, $priorCloseId));
        });

        self::assertSame('restated', DB::table('budgeting_report_source_closes')->where('close_id', $priorCloseId)->value('status'));
        self::assertSame($priorCloseId, DB::table('budgeting_report_source_closes')->where('close_id', $replacementCloseId)->value('restates_close_id'));
    }

    public function test_restatement_without_reverse_replacement_is_rejected_at_commit(): void
    {
        $priorCloseId = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
        DB::table('budgeting_report_source_closes')->insert($this->close($priorCloseId));

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($priorCloseId): void {
            DB::table('budgeting_report_source_closes')->where('close_id', $priorCloseId)->update([
                'status' => 'restated',
                'restated_by' => 900000001,
                'restated_at' => '2026-02-01 00:00:00+00',
                'restated_by_close_id' => '01K00000000000000000000000',
            ]);
        });
    }

    public function test_restatement_with_replacement_from_different_identity_is_rejected_at_commit(): void
    {
        $priorCloseId = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
        $replacementCloseId = '01K00000000000000000000000';
        DB::table('budgeting_report_source_closes')->insert($this->close($priorCloseId));

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($priorCloseId, $replacementCloseId): void {
            DB::table('budgeting_report_source_closes')->where('close_id', $priorCloseId)->update([
                'status' => 'restated',
                'restated_by' => 900000001,
                'restated_at' => '2026-02-01 00:00:00+00',
                'restated_by_close_id' => $replacementCloseId,
            ]);
            DB::table('budgeting_report_source_closes')->insert($this->close(
                $replacementCloseId,
                $priorCloseId,
                'budget-v3'
            ));
        });
    }

    private function close(string $closeId, ?string $restatesCloseId = null, string $planIdentity = 'budget-v2'): array
    {
        return [
            'close_id' => $closeId,
            'organization_id' => 700001,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'scenario_identity' => 'base',
            'plan_identity' => $planIdentity,
            'formula_version' => 'margin-v1',
            'source_manifest' => json_encode(['budget_version' => 'budget-v2'], JSON_THROW_ON_ERROR),
            'content_hash' => str_repeat('a', 64),
            'approved_by' => 900000001,
            'approved_at' => '2026-01-31 18:00:00+00',
            'retained_until' => '2033-01-31 00:00:00+00',
            'status' => 'approved',
            'restates_close_id' => $restatesCloseId,
            'restated_by' => null,
            'restated_at' => null,
            'restated_by_close_id' => null,
            'created_at' => '2026-01-31 18:00:00+00',
            'updated_at' => '2026-01-31 18:00:00+00',
        ];
    }
}
