<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\Jobs\ProcessEstimateOperation;
use App\Models\EstimateOperation;
use App\Models\EstimateOperationRow;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EstimateOperationService
{
    public function __construct(private readonly EstimateOperationAccess $access, private readonly EstimateOperationSnapshot $snapshots, private readonly EstimateOperationCalculator $calculator, private readonly EstimateOperationWriter $writer)
    {
    }

    public function create(User $actor, int $projectId, array $parameters): EstimateOperation
    {
        $organizationId = (int) $actor->current_organization_id;
        $this->access->project($actor, $organizationId, $projectId);
        $quantityMode = $parameters['subtraction_quantity_mode'] ?? 'positive';
        if (! in_array($parameters['mode'] ?? null, ['adjust', 'consolidate', 'reprice'], true) || ! in_array($quantityMode, ['positive', 'signed'], true)
            || (array_key_exists('subtraction_quantity_mode', $parameters) && ($parameters['mode'] !== 'adjust' || ! in_array($parameters['reprice'] ?? false, [false, 0, '0'], true)))) {
            $this->invalid('invalid');
        }
        if ($parameters['mode'] === 'adjust' && ! ($parameters['reprice'] ?? false)) {
            $parameters['subtraction_quantity_mode'] = $quantityMode;
        }
        $ids = $parameters['mode'] === 'adjust'
            ? array_merge([$parameters['base_id']], $parameters['addition_ids'] ?? [], $parameters['subtraction_ids'] ?? [])
            : $parameters['source_ids'];
        if (count($ids) !== count(array_unique($ids)) || count($ids) > 20) {
            $this->invalid('invalid');
        }
        foreach ($ids as $id) {
            $this->access->source($actor, $organizationId, $projectId, (int) $id);
        }
        $priceListIds = $parameters['price_list_ids'] ?? [];
        if (\App\Models\EstimateOperationPriceList::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereIn('id', $priceListIds)->count() !== count($priceListIds)) {
            $this->invalid('invalid');
        }
        $template = $this->access->source($actor, $organizationId, $projectId, (int) $ids[0]);
        $parameters['rate_overrides'] = array_values(array_intersect(['overhead_rate', 'profit_rate'], array_keys($parameters)));
        foreach (['vat_rate', 'overhead_rate', 'profit_rate'] as $field) {
            $parameters[$field] = (string) ($parameters[$field] ?? $template->{$field} ?? '0');
        }
        $parameters['currency'] ??= (string) ($template->metadata['currency'] ?? 'RUB');
        $parameters['name'] ??= trans_message('estimate_operations.result_name');
        $operation = EstimateOperation::query()->create([
            'id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'project_id' => $projectId, 'actor_id' => $actor->id,
            'mode' => $parameters['mode'], 'parameters' => $parameters, 'status' => 'queued', 'resolutions' => [], 'generation' => 1,
        ]);
        $this->dispatch($operation, false);

        return $operation->refresh();
    }

    public function find(User $actor, int $projectId, string $id): EstimateOperation
    {
        $this->access->project($actor, (int) $actor->current_organization_id, $projectId);
        $operation = EstimateOperation::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)->findOrFail($id);
        if ((int) $operation->actor_id !== (int) $actor->id) {
            throw new AuthorizationException(trans_message('estimate_operations.access_denied'));
        }
        $parameters = $operation->parameters;
        $ids = $operation->mode === 'adjust' ? array_merge([$parameters['base_id']], $parameters['addition_ids'] ?? [], $parameters['subtraction_ids'] ?? []) : $parameters['source_ids'];
        foreach ($ids as $sourceId) {
            $this->access->source($actor, (int) $operation->organization_id, $projectId, (int) $sourceId);
        }

        return $operation;
    }

    public function show(User $actor, int $projectId, string $id, int $page = 1, bool $onlyConflicts = false): array
    {
        $operation = $this->find($actor, $projectId, $id);
        $rows = EstimateOperationRow::query()->where('operation_id', $id)->when($onlyConflicts, fn ($q) => $q->where('has_conflicts', true))->orderBy('position')->paginate(50, ['*'], 'page', max(1, $page));

        return $this->payload($operation) + ['rows' => $rows->map(function ($row): array {
            $payload = $row->payload;
            unset($payload['node']);

            return $payload;
        })->all(), 'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => 50]];
    }

    public function resolve(User $actor, int $projectId, string $id, array $resolutions, bool $refresh = false): EstimateOperation
    {
        $this->find($actor, $projectId, $id);
        $operation = DB::transaction(function () use ($id, $resolutions, $refresh): EstimateOperation {
            $operation = EstimateOperation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($operation->status, ['ready', 'needs_resolution', 'failed', 'stale'], true)) {
                $this->invalid('not_ready');
            }
            $operation->update(['resolutions' => $refresh ? [] : array_merge($operation->resolutions ?? [], $resolutions),
                'source_snapshot' => $refresh ? null : $operation->source_snapshot, 'status' => 'queued', 'generation' => $operation->generation + 1, 'error_code' => null, 'apply_key' => null]);

            return $operation;
        });
        $this->dispatch($operation, false);

        return $operation->refresh();
    }

    public function apply(User $actor, int $projectId, string $id, string $key): EstimateOperation
    {
        $this->find($actor, $projectId, $id);
        $operation = DB::transaction(function () use ($id, $key): EstimateOperation {
            $operation = EstimateOperation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($operation->apply_key !== null && $operation->apply_key !== $key) {
                $this->invalid('key_conflict');
            }
            if (in_array($operation->status, ['applying', 'completed'], true)) {
                return $operation;
            }
            if ($operation->status !== 'ready' || ($operation->summary['conflict_count'] ?? 1) !== 0) {
                $this->invalid('not_ready');
            }
            $operation->update(['status' => 'applying', 'apply_key' => $key]);

            return $operation;
        });
        if ($operation->wasChanged('status')) {
            $this->dispatch($operation, true);
        }

        return $operation->refresh();
    }

    public function process(string $id, int $generation, bool $apply): void
    {
        DB::transaction(function () use ($id, $generation, $apply): void {
            $operation = EstimateOperation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($operation->generation !== $generation || ! in_array($operation->status, $apply ? ['applying'] : ['queued', 'processing'], true)) {
                return;
            }
            $actor = User::query()->findOrFail($operation->actor_id);
            $actor->current_organization_id = (int) $operation->organization_id;
            $this->find($actor, (int) $operation->project_id, $id);
            $operation->update(['status' => $apply ? 'applying' : 'processing']);
            $snapshot = $this->snapshots->capture($operation, $actor, $apply);
            $oldDigest = $operation->source_snapshot['digest'] ?? null;
            if ($oldDigest !== null && $snapshot['digest'] !== $oldDigest) {
                $operation->update(['status' => 'stale', 'error_code' => 'source_changed']);

                return;
            }
            $calculation = $this->calculator->calculate($snapshot['sources'], $operation->parameters, $snapshot['prices'], $operation->resolutions ?? []);
            if ($apply) {
                if ($calculation['summary']['conflict_count'] !== 0) {
                    $this->invalid('not_ready');
                }
                $ids = $this->writer->write($operation, $snapshot['sources'], $calculation['output']);
                $operation->update(['status' => 'completed', 'result_ids' => $ids]);

                return;
            }
            EstimateOperationRow::query()->where('operation_id', $id)->delete();
            $insert = [];
            foreach ($calculation['rows'] as $position => $row) {
                $insert[] = ['operation_id' => $id, 'row_key' => $row['key'], 'position' => $position, 'has_conflicts' => $row['conflicts'] !== [], 'payload' => json_encode($row, JSON_THROW_ON_ERROR)];
                if (count($insert) === 100) {
                    EstimateOperationRow::query()->insert($insert);
                    $insert = [];
                }
            }
            if ($insert !== []) {
                EstimateOperationRow::query()->insert($insert);
            }
            $operation->update(['source_snapshot' => ['digest' => $snapshot['digest'], 'headers' => $snapshot['headers'], 'sources' => $snapshot['sources'], 'price_sources' => $snapshot['price_sources']], 'summary' => $calculation['summary'], 'status' => $calculation['summary']['conflict_count'] === 0 ? 'ready' : 'needs_resolution']);
        });
    }

    public function fail(string $id, int $generation, ?Throwable $exception): void
    {
        EstimateOperation::query()->whereKey($id)->where('generation', $generation)->whereIn('status', ['queued', 'processing', 'applying'])->update(['status' => 'failed', 'error_code' => 'processing_failed']);
        Log::error('estimate_operation.failed', ['operation_id' => $id, 'generation' => $generation, 'exception_class' => $exception === null ? null : $exception::class]);
    }

    public function payload(EstimateOperation $operation): array
    {
        return ['id' => $operation->id, 'mode' => $operation->mode, 'status' => $operation->status, 'generation' => $operation->generation,
            'parameters' => $operation->parameters, 'summary' => $operation->summary, 'result_ids' => $operation->result_ids ?? [],
            'error_code' => $operation->error_code, 'error_message' => $operation->error_code ? trans_message('estimate_operations.'.$operation->error_code) : null];
    }

    private function dispatch(EstimateOperation $operation, bool $apply): void
    {
        try {
            ProcessEstimateOperation::dispatch($operation->id, $operation->generation, $apply)->afterCommit();
        } catch (Throwable $exception) {
            $this->fail($operation->id, $operation->generation, $exception);
            throw $exception;
        }
    }

    private function invalid(string $code): never
    {
        throw ValidationException::withMessages(['operation' => trans_message('estimate_operations.'.$code)]);
    }
}
