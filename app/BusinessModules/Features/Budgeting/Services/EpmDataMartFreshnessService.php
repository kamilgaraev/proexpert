<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

use function trans_message;

final class EpmDataMartFreshnessService
{
    public function decoratePayload(array $payload, EpmDataMartScope $scope): array
    {
        $metadata = $this->metadata($scope);
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['data_mart'] = $metadata;

        if (is_array($payload['meta']['freshness'] ?? null)) {
            $payload['meta']['freshness']['data_mart'] = $metadata['freshness'];
        } elseif (is_array($payload['freshness'] ?? null)) {
            $payload['freshness']['data_mart'] = $metadata['freshness'];
        } else {
            $payload['meta']['freshness'] = [
                'data_mart' => $metadata['freshness'],
            ];
        }

        return $payload;
    }

    public function metadata(EpmDataMartScope $scope): array
    {
        return $this->metadataFor(
            scope: $scope,
            snapshot: $this->latestSnapshot($scope),
            run: $this->latestRun($scope),
        );
    }

    public function metadataFor(EpmDataMartScope $scope, ?EpmDataMartSnapshot $snapshot, ?EpmDataMartRecalculationRun $run = null): array
    {
        $status = $this->publicStatus($snapshot, $run);
        $calculationSource = $snapshot instanceof EpmDataMartSnapshot ? 'data_mart' : 'online';

        if ($status === EpmDataMartStatus::FAILED && !$snapshot instanceof EpmDataMartSnapshot) {
            $calculationSource = 'online';
        }

        return [
            'status' => $status,
            'calculation_source' => $calculationSource,
            'message' => $this->message($status),
            'report_scope' => $scope->reportScope,
            'scope_hash' => $scope->scopeHash(),
            'formula_version' => EpmDataMartPayloadProjector::FORMULA_VERSION,
            'freshness' => [
                'status' => $status,
                'generated_at' => $this->modelDateTime($snapshot, 'generated_at'),
                'stale_at' => $this->modelDateTime($snapshot, 'stale_at'),
            ],
            'snapshot' => $snapshot instanceof EpmDataMartSnapshot ? [
                'uuid' => (string) $snapshot->uuid,
                'status' => $this->snapshotStatus($snapshot),
                'generated_at' => $this->modelDateTime($snapshot, 'generated_at'),
                'formula_version' => (string) $snapshot->formula_version,
                'source_hash' => (string) $snapshot->source_hash,
                'source_refs' => is_array($snapshot->source_refs) ? $snapshot->source_refs : [],
            ] : null,
            'recalculation' => $run instanceof EpmDataMartRecalculationRun ? [
                'uuid' => (string) $run->uuid,
                'status' => (string) $run->status,
                'queued_at' => $this->modelDateTime($run, 'queued_at'),
                'started_at' => $this->modelDateTime($run, 'started_at'),
                'finished_at' => $this->modelDateTime($run, 'finished_at'),
                'error_summary' => is_array($run->error_summary) ? $run->error_summary : null,
            ] : null,
        ];
    }

    private function latestSnapshot(EpmDataMartScope $scope): ?EpmDataMartSnapshot
    {
        return EpmDataMartSnapshot::query()
            ->forScope($scope->organizationId, $scope->reportScope, $scope->scopeHash())
            ->whereNull('superseded_at')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function latestRun(EpmDataMartScope $scope): ?EpmDataMartRecalculationRun
    {
        return EpmDataMartRecalculationRun::query()
            ->forScope($scope->organizationId, $scope->reportScope, $scope->scopeHash())
            ->orderByDesc('id')
            ->first();
    }

    private function publicStatus(?EpmDataMartSnapshot $snapshot, ?EpmDataMartRecalculationRun $run): string
    {
        if ($run instanceof EpmDataMartRecalculationRun && EpmDataMartStatus::isActive((string) $run->status)) {
            return (string) $run->status;
        }

        if ($run instanceof EpmDataMartRecalculationRun && (string) $run->status === EpmDataMartStatus::FAILED) {
            if (!$snapshot instanceof EpmDataMartSnapshot || $this->runIsNewerThanSnapshot($run, $snapshot)) {
                return EpmDataMartStatus::FAILED;
            }
        }

        if (!$snapshot instanceof EpmDataMartSnapshot) {
            return EpmDataMartStatus::ONLINE;
        }

        return $this->snapshotStatus($snapshot);
    }

    private function snapshotStatus(EpmDataMartSnapshot $snapshot): string
    {
        if ((string) $snapshot->formula_version !== EpmDataMartPayloadProjector::FORMULA_VERSION) {
            return EpmDataMartStatus::STALE;
        }

        $staleAt = $this->modelDateTimeObject($snapshot, 'stale_at');
        if ($staleAt instanceof CarbonInterface && $staleAt->lte(now())) {
            return EpmDataMartStatus::STALE;
        }

        return EpmDataMartStatus::normalize($snapshot->status);
    }

    private function runIsNewerThanSnapshot(EpmDataMartRecalculationRun $run, EpmDataMartSnapshot $snapshot): bool
    {
        $finishedAt = $this->modelDateTimeObject($run, 'finished_at');
        $generatedAt = $this->modelDateTimeObject($snapshot, 'generated_at');

        return $finishedAt instanceof CarbonInterface
            && $generatedAt instanceof CarbonInterface
            && $finishedAt->gte($generatedAt);
    }

    private function message(string $status): string
    {
        return match ($status) {
            EpmDataMartStatus::QUEUED => trans_message('budgeting.epm_data_mart.messages.queued'),
            EpmDataMartStatus::RUNNING => trans_message('budgeting.epm_data_mart.messages.running'),
            EpmDataMartStatus::SUCCEEDED => trans_message('budgeting.epm_data_mart.messages.succeeded'),
            EpmDataMartStatus::PARTIAL => trans_message('budgeting.epm_data_mart.messages.partial'),
            EpmDataMartStatus::STALE => trans_message('budgeting.epm_data_mart.messages.stale'),
            EpmDataMartStatus::FAILED => trans_message('budgeting.epm_data_mart.messages.failed'),
            default => trans_message('budgeting.epm_data_mart.messages.online'),
        };
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function modelDateTime(?Model $model, string $attribute): ?string
    {
        return $this->dateTime($this->modelDateTimeObject($model, $attribute));
    }

    private function modelDateTimeObject(?Model $model, string $attribute): ?CarbonInterface
    {
        if (!$model instanceof Model) {
            return null;
        }

        $value = $model->getRawOriginal($attribute);
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
