<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ContractParticipantConcurrencyTest extends TestCase
{
    public function test_two_concurrent_assignments_create_only_one_general_contractor(): void
    {
        $owner = Organization::factory()->create();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        DB::commit();
        $raceName = 'contract-gc-'.bin2hex(random_bytes(5));
        $environment = $this->workerEnvironment($raceName);
        $workers = [];
        DB::beginTransaction();
        try {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            foreach ([$first->id, $second->id] as $organizationId) {
                $worker = new Process([
                    PHP_BINARY, base_path('tests/Support/Contract/assign_general_contractor_worker.php'),
                    (string) $project->id, (string) $organizationId,
                ], base_path(), $environment, timeout: 35);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $locked = DB::table('pg_stat_activity')->where('application_name', $raceName)
                    ->where('wait_event_type', 'Lock')->count();
                if ($locked === 2) {
                    break;
                }
                foreach ($workers as $worker) {
                    if (!$worker->isRunning()) {
                        self::fail($worker->getErrorOutput().$worker->getOutput());
                    }
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $locked, 'Both independent requests must wait for the project row');
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['success']));
            $rejected = array_values(array_filter($results, static fn (array $result): bool => !$result['success']));
            self::assertCount(1, $rejected);
            self::assertSame(409, $rejected[0]['code']);
            self::assertSame(trans_message('project.unique_general_contractor_conflict'), $rejected[0]['message']);
            self::assertSame(1, DB::table('project_organization')->where('project_id', $project->id)
                ->where('role_new', 'general_contractor')->where('is_active', true)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::beginTransaction();
        }
    }

    public function test_waiting_contract_update_observes_new_payment_after_acquiring_lock(): void
    {
        $owner = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $first = \App\Models\Contractor::create(['organization_id' => $owner->id, 'name' => 'Первый', 'contractor_type' => 'manual']);
        $second = \App\Models\Contractor::create(['organization_id' => $owner->id, 'name' => 'Второй', 'contractor_type' => 'manual']);
        $contract = \App\Models\Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $first->id,
            'number' => 'RACE-1', 'date' => '2026-09-19', 'status' => 'active',
            'contract_side_type' => 'contract', 'base_amount' => 100, 'total_amount' => 100,
        ]);
        app(\App\Services\Contract\ContractPartySnapshotService::class)->syncParties($contract);
        $originalParties = $contract->parties()->orderBy('id')->get()->toArray();
        DB::commit();
        $raceName = 'contract-edit-'.bin2hex(random_bytes(5));
        $worker = new Process([
            PHP_BINARY, base_path('tests/Support/Contract/replace_contract_executor_worker.php'),
            (string) $contract->id, (string) $second->id,
        ], base_path(), $this->workerEnvironment($raceName), timeout: 35);
        DB::beginTransaction();
        try {
            \App\Models\Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $deadline = microtime(true) + 15;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $locked = DB::table('pg_stat_activity')->where('application_name', $raceName)
                    ->where('wait_event_type', 'Lock')->exists();
                if ($locked) {
                    break;
                }
                if (!$worker->isRunning()) {
                    self::fail($worker->getErrorOutput().$worker->getOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($locked, 'Update must wait for the contract row');
            $contract->payments()->create([
                'organization_id' => $owner->id, 'project_id' => $project->id,
                'invoiceable_type' => \App\Models\Contract::class, 'document_number' => 'RACE-PAY-1',
                'document_date' => '2026-09-19', 'document_type' => 'invoice', 'direction' => 'outgoing',
                'invoice_type' => 'act', 'amount' => 100, 'currency' => 'RUB', 'status' => 'draft',
            ]);
            DB::commit();
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $result = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            self::assertFalse($result['success']);
            self::assertSame(trans_message('contracts.parties_locked_by_execution'), $result['message']);
            self::assertSame($first->id, $contract->fresh()->contractor_id);
            self::assertSame($originalParties, $contract->parties()->orderBy('id')->get()->toArray());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            DB::beginTransaction();
        }
    }

    public function test_waiting_payment_uses_parties_and_currency_committed_by_contract_change(): void
    {
        $owner = Organization::factory()->create();
        $executor = Organization::factory()->create();
        $replacement = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $first = \App\Models\Contractor::create(['organization_id' => $owner->id, 'name' => 'First', 'source_organization_id' => $executor->id]);
        $second = \App\Models\Contractor::create(['organization_id' => $owner->id, 'name' => 'Second', 'source_organization_id' => $replacement->id]);
        $contract = \App\Models\Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id, 'contractor_id' => $first->id,
            'number' => 'PAYMENT-RACE', 'date' => '2026-09-19', 'status' => 'active',
            'contract_side_type' => 'contract', 'base_amount' => 100, 'total_amount' => 100, 'currency' => 'USD',
        ]);
        app(\App\Services\Contract\ContractPartySnapshotService::class)->syncParties($contract);
        DB::commit();
        $raceName = 'contract-payment-'.bin2hex(random_bytes(5));
        $worker = new Process([
            PHP_BINARY, base_path('tests/Support/Contract/create_contract_payment_worker.php'), (string) $contract->id,
        ], base_path(), $this->workerEnvironment($raceName), timeout: 35);
        DB::beginTransaction();
        try {
            \App\Models\Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $deadline = microtime(true) + 15;
            $locked = false;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $locked = DB::table('pg_stat_activity')->where('application_name', $raceName)
                    ->where('wait_event_type', 'Lock')->exists();
                if ($locked) {
                    break;
                }
                if (!$worker->isRunning()) {
                    self::fail($worker->getErrorOutput().$worker->getOutput());
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($locked, 'Payment must wait for the contract row');
            $contract->update(['contractor_id' => $second->id, 'currency' => 'EUR']);
            $contract->secondParty()->update(['linked_organization_id' => $replacement->id, 'name' => $replacement->name]);
            DB::commit();
            $worker->wait();
            $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertTrue($result['success'], $result['message'] ?? $worker->getErrorOutput());
            $payment = \App\BusinessModules\Core\Payments\Models\PaymentDocument::findOrFail($result['id']);
            self::assertSame($owner->id, $payment->payer_organization_id);
            self::assertSame($replacement->id, $payment->payee_organization_id);
            self::assertSame('EUR', $payment->currency);
            self::assertSame(1, $contract->payments()->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
            DB::beginTransaction();
        }
    }

    private function workerEnvironment(string $raceName): array
    {
        $connection = (array) config('database.connections.'.config('database.default'));

        return array_merge(is_array(getenv()) ? getenv() : [], [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'MOST_RACE_NAME' => $raceName,
        ]);
    }
}
