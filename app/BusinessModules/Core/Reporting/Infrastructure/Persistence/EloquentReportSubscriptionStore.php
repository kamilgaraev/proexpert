<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionWindow;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionFrequency;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportSubscriptionRecord;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentReportSubscriptionStore implements ReportSubscriptionStore
{
    public function list(int $organizationId, int $ownerId, ReportSubscriptionWindow $window): ReportSubscriptionPage
    {
        $query = ReportSubscriptionRecord::query()->where('organization_id', $organizationId)->where('owner_id', $ownerId)
            ->when(
                $window->status !== null,
                fn ($query) => $query->where('status', $window->status->value),
                fn ($query) => $query->where('status', '!=', ReportSubscriptionStatus::DELETED->value),
            )
            ->orderByRaw('next_run_at ASC NULLS LAST')->orderBy('id');
        if ($window->cursor !== null) {
            [$at, $id] = explode('|', $window->cursor, 2);
            $query->where(fn ($query) => $at === 'null'
                ? $query->whereNull('next_run_at')->where('id', '>', $id)
                : $query->where(fn ($query) => $query
                    ->where('next_run_at', '>', $at)
                    ->orWhere(fn ($query) => $query->where('next_run_at', $at)->where('id', '>', $id))
                    ->orWhereNull('next_run_at')));
        }
        $rows = $query->limit($window->limit + 1)->get();
        $items = $rows->take($window->limit)->map(fn (ReportSubscriptionRecord $record) => $this->dto($record))->all();
        $last = $items[array_key_last($items)] ?? null;
        $hasMore = $rows->count() > $window->limit;

        return new ReportSubscriptionPage($items, $hasMore && $last instanceof ReportSubscription ? ($last->nextRunAt?->format(DATE_ATOM) ?? 'null').'|'.$last->id : null, $window->limit, $hasMore);
    }

    public function getForActor(int $organizationId, int $ownerId, string $id): ReportSubscription
    {
        $record = ReportSubscriptionRecord::query()->where('id', $id)->where('organization_id', $organizationId)->where('owner_id', $ownerId)->first();
        if (! $record instanceof ReportSubscriptionRecord) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
        }

        return $this->dto($record);
    }

    public function lock(string $id): ReportSubscription
    {
        return $this->dto(ReportSubscriptionRecord::query()->where('id', $id)->lockForUpdate()->firstOrFail());
    }

    public function create(ReportSubscription $subscription): ReportSubscription
    {
        return $this->dto(ReportSubscriptionRecord::query()->create($this->attributes($subscription)));
    }

    public function updateLocked(ReportSubscription $subscription, array $changes): ReportSubscription
    {
        DB::transaction(fn () => $this->mutation($subscription, $changes + ['transition_version' => $subscription->transitionVersion + 1]));

        return $this->getForActor($subscription->organizationId, $subscription->ownerId, $subscription->id);
    }

    public function softDeleteLocked(ReportSubscription $subscription): void
    {
        DB::transaction(fn () => $this->mutation($subscription, ['status' => 'deleted', 'next_run_at' => null, 'transition_version' => $subscription->transitionVersion + 1]));
    }

    public function selectDueLocked(DateTimeImmutable $now, int $limit): array
    {
        return ReportSubscriptionRecord::query()->where('status', 'active')->where('next_run_at', '<=', $now)->orderBy('next_run_at')->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->limit($limit)->get()->map(fn (ReportSubscriptionRecord $record) => $this->dto($record))->all();
    }

    public function advanceNextRunLocked(ReportSubscription $subscription, DateTimeImmutable $nextRun): void
    {
        $this->mutation($subscription, ['next_run_at' => $nextRun, 'transition_version' => $subscription->transitionVersion + 1]);
    }

    public function disableLocked(ReportSubscription $subscription, string $reason): void
    {
        $this->mutation($subscription, ['status' => 'disabled', 'disabled_reason' => $reason, 'next_run_at' => null, 'transition_version' => $subscription->transitionVersion + 1]);
    }

    private function mutation(ReportSubscription $subscription, array $changes): void
    {
        if (ReportSubscriptionRecord::query()->where('id', $subscription->id)->where('transition_version', $subscription->transitionVersion)->update($changes) !== 1) {
            throw new LogicException('report_subscription_concurrent_change');
        }
    }

    private function attributes(ReportSubscription $subscription): array
    {
        return ['id' => $subscription->id, 'organization_id' => $subscription->organizationId, 'owner_id' => $subscription->ownerId, 'saved_view_id' => $subscription->savedViewId, 'report_code' => $subscription->reportCode, 'frequency' => $subscription->frequency->value, 'weekday' => $subscription->weekday, 'day_of_month' => $subscription->dayOfMonth, 'local_time' => $subscription->localTime, 'timezone' => $subscription->timezone->getName(), 'period_policy_json' => $subscription->periodPolicy, 'format' => $subscription->format, 'channel' => 'in_app', 'status' => $subscription->status->value, 'disabled_reason' => $subscription->disabledReason, 'consecutive_failures' => $subscription->consecutiveFailures, 'next_run_at' => $subscription->nextRunAt, 'execution_input_bytes' => $subscription->executionInputBytes, 'execution_input_sha256' => $subscription->executionInputHash->value, 'definition_sha256' => $subscription->definitionHash->value, 'contract_version' => $subscription->contractVersion, 'transition_version' => $subscription->transitionVersion];
    }

    private function dto(ReportSubscriptionRecord $record): ReportSubscription
    {
        $date = fn ($value): DateTimeImmutable => DateTimeImmutable::createFromInterface($value);

        return new ReportSubscription($record->id, (int) $record->organization_id, (int) $record->owner_id, $record->saved_view_id, $record->report_code, ReportSubscriptionFrequency::from($record->frequency), $record->weekday, $record->day_of_month, $record->local_time, new DateTimeZone($record->timezone), (array) $record->period_policy_json, $record->format, ReportSubscriptionStatus::from($record->status), $record->disabled_reason, (int) $record->consecutive_failures, $record->next_run_at ? $date($record->next_run_at) : null, $record->execution_input_bytes, new Sha256Hash($record->execution_input_sha256), new Sha256Hash($record->definition_sha256), $record->contract_version, (int) $record->transition_version, $date($record->created_at), $date($record->updated_at));
    }
}
