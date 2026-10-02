<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RagForeignKeyLockTest extends TestCase
{
    private ?Connection $probe = null;

    private ?int $organizationId = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->probe !== null && $this->organizationId !== null) {
            $this->probe->table('organizations')->where('id', $this->organizationId)->delete();
        }
    }

    public function test_single_and_batch_queue_serialize_without_blocking_organization_foreign_keys(): void
    {
        config(['database.connections.slow_sql_probe' => config('database.connections.pgsql')]);
        $this->probe = DB::connection('slow_sql_probe');
        $organization = Organization::withoutEvents(function (): Organization {
            $organization = (new Organization)->setConnection('slow_sql_probe')->forceFill(Organization::factory()->raw());
            $organization->save();

            return $organization;
        });
        $this->organizationId = (int) $organization->id;
        $this->probe->statement("SET lock_timeout = '100ms'");
        $coordinator = app(RagIndexingCoordinator::class);
        $coordinator->queueEntity($organization->id, null, 'project', 'project', 1);
        $this->assertForeignKeyChecksAreUnblocked();
        $coordinator->queueEntities($organization->id, null, 'project', 'project', [2, 3]);
        $this->assertForeignKeyChecksAreUnblocked();
        try {
            $this->probe->select('SELECT id FROM organizations WHERE id = ? FOR NO KEY UPDATE NOWAIT', [$organization->id]);
            self::fail('Concurrent queue writers must still serialize.');
        } catch (QueryException $exception) {
            self::assertSame('55P03', $exception->errorInfo[0]);
        }
        $references = DB::select("SELECT DISTINCT conrelid::regclass::text AS child FROM pg_constraint WHERE contype = 'f' AND confrelid = 'organizations'::regclass AND conrelid::regclass::text IN ('ai_usage_records','ai_rag_sources','design_packages','design_ifc_upload_sessions','design_model_derivatives')");
        self::assertCount(5, $references);
        Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
        try {
            $this->probe->select('SELECT id FROM organizations WHERE id = ? FOR KEY SHARE', [$organization->id]);
            self::fail('The previous FOR UPDATE lock must reproduce the foreign-key wait.');
        } catch (QueryException $exception) {
            self::assertSame('55P03', $exception->errorInfo[0]);
        }
    }

    private function assertForeignKeyChecksAreUnblocked(): void
    {
        self::assertSame($this->organizationId, (int) $this->probe->selectOne('SELECT id FROM organizations WHERE id = ? FOR KEY SHARE', [$this->organizationId])->id);
    }
}
