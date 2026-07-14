<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\RunEstimateGenerationBenchmarkJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBenchmarkRun;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\Filament\Support\FilamentPermission;
use App\Models\SystemAdmin;
use DomainException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

final readonly class AdminBenchmarkDispatchService
{
    public function __construct(
        private BenchmarkRunRepository $runs,
        private Dispatcher $bus,
    ) {}

    /** @return array{run_id: int, run_uuid: string, idempotent_replay: bool} */
    public function handle(AdminBenchmarkDispatchCommand $command): array
    {
        [$actor, $canRunAcceptance] = $this->assertAllowed($command->actorId);
        $this->assertCommand($command);

        return DB::transaction(function () use ($actor, $canRunAcceptance, $command): array {
            $operation = $this->claim($command);
            if ((string) $operation->status === 'completed') {
                return $this->replay($operation->result);
            }

            $dataset = EstimateGenerationTrainingDataset::query()
                ->whereKey($command->datasetId)
                ->where('organization_id', $command->organizationId)
                ->lockForUpdate()
                ->first();
            if (! $dataset instanceof EstimateGenerationTrainingDataset) {
                throw new DomainException('benchmark_dataset_not_found');
            }
            if (! BenchmarkDispatchPolicy::allows(
                (string) $dataset->dataset_type,
                (string) $dataset->status,
                $command->confirmedAcceptance,
                $canRunAcceptance,
            )) {
                throw new DomainException('benchmark_dispatch_not_allowed');
            }

            $datasetManifest = is_array($dataset->stats) ? ($dataset->stats['benchmark_manifest'] ?? null) : null;
            if (! is_array($datasetManifest)) {
                throw new DomainException('benchmark_dataset_manifest_missing');
            }
            $executionSnapshot = BenchmarkExecutionSnapshot::fromArray([
                'schema_version' => 1,
                'organization_id' => (int) $dataset->organization_id,
                'dataset_id' => (int) $dataset->id,
                'dataset_type' => (string) $dataset->dataset_type,
                'dataset_version' => (int) $dataset->version,
                'dataset_content_hash' => $datasetManifest['dataset_content_hash'] ?? null,
                'manifest_locator' => $datasetManifest['locator'] ?? null,
                'manifest_sha256' => $datasetManifest['sha256'] ?? null,
                'adapter_id' => $command->manifest['adapter_id'] ?? null,
                'prompt_version' => $command->manifest['prompt_version'] ?? null,
                'settings_snapshot_id' => $command->manifest['settings_snapshot_id'] ?? null,
                'settings_snapshot_version' => $command->manifest['settings_snapshot_version'] ?? null,
                'pipeline_version' => $command->manifest['pipeline_version'] ?? null,
                'model_versions' => $command->manifest['model_versions'] ?? null,
                'normative_version' => $command->manifest['normative_version'] ?? null,
                'price_version' => $command->manifest['price_version'] ?? null,
                'currency' => $command->manifest['currency'] ?? null,
            ]);
            $manifest = [...$command->manifest, 'organization_id' => $command->organizationId, 'execution_snapshot' => $executionSnapshot->toArray()];
            $run = $this->runs->start($dataset, $manifest, $command->idempotencyKey);
            if ($run->wasRecentlyCreated) {
                $this->bus->dispatch((new RunEstimateGenerationBenchmarkJob(
                    (int) $run->id,
                    $command->idempotencyKey,
                ))->afterCommit());
            }
            $result = ['run_id' => (int) $run->id, 'run_uuid' => (string) $run->uuid];
            $this->recordAudit($actor, $command, $run);
            DB::table('estimate_generation_admin_action_operations')->where('id', $operation->id)->update([
                'status' => 'completed',
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'completed_at' => now(),
            ]);

            return [...$result, 'idempotent_replay' => ! $run->wasRecentlyCreated];
        });
    }

    /** @return array{SystemAdmin, bool} */
    private function assertAllowed(int $actorId): array
    {
        $actor = SystemAdmin::query()->find($actorId);
        if (! $actor instanceof SystemAdmin
            || ! $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_BENCHMARKS)) {
            throw new DomainException('benchmark_dispatch_forbidden');
        }
        $canRunAcceptance = $actor->hasSystemPermission(FilamentPermission::ESTIMATE_GENERATION_DATASETS);

        return [$actor, $canRunAcceptance];
    }

    private function assertCommand(AdminBenchmarkDispatchCommand $command): void
    {
        if ($command->actorId <= 0 || $command->datasetId <= 0 || $command->organizationId <= 0
            || preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $command->idempotencyKey) !== 1) {
            throw new DomainException('benchmark_dispatch_invalid');
        }
    }

    private function claim(AdminBenchmarkDispatchCommand $command): object
    {
        DB::table('estimate_generation_admin_action_operations')->insertOrIgnore([
            'organization_id' => $command->organizationId,
            'operation' => 'benchmark_run',
            'subject_id' => $command->datasetId,
            'idempotency_key' => $command->idempotencyKey,
            'command_fingerprint' => $command->fingerprint(),
            'status' => 'pending',
            'result' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'completed_at' => null,
        ]);
        $operation = DB::table('estimate_generation_admin_action_operations')
            ->where('organization_id', $command->organizationId)
            ->where('operation', 'benchmark_run')
            ->where('idempotency_key', $command->idempotencyKey)
            ->lockForUpdate()
            ->first();
        if (! is_object($operation) || ! hash_equals((string) $operation->command_fingerprint, $command->fingerprint())) {
            throw new DomainException('benchmark_dispatch_idempotency_conflict');
        }

        return $operation;
    }

    private function recordAudit(
        SystemAdmin $actor,
        AdminBenchmarkDispatchCommand $command,
        EstimateGenerationBenchmarkRun $run,
    ): void {
        DB::table('estimate_generation_admin_action_audits')->insert([
            'organization_id' => $command->organizationId,
            'actor_system_admin_id' => $actor->id,
            'operation' => 'benchmark_run',
            'subject_id' => $command->datasetId,
            'command_fingerprint' => $command->fingerprint(),
            'result' => json_encode(['run_id' => (int) $run->id, 'run_uuid' => (string) $run->uuid], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @return array{run_id: int, run_uuid: string, idempotent_replay: bool} */
    private function replay(mixed $value): array
    {
        $result = is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($result) || array_keys($result) !== ['run_id', 'run_uuid']
            || ! is_int($result['run_id']) || $result['run_id'] <= 0
            || ! is_string($result['run_uuid']) || strlen($result['run_uuid']) > 64) {
            throw new DomainException('benchmark_dispatch_replay_invalid');
        }

        return [...$result, 'idempotent_replay' => true];
    }
}
