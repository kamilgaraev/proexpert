<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\Billing\CommercialBillingConflictException;
use App\Models\CommercialContourChange;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CommercialContourChangeService
{
    public function __construct(
        private readonly CommercialOfferCalculator $calculator,
        private readonly CommercialBillingQueryService $billing,
        private readonly CommercialSelfServiceGuard $selfServiceGuard,
    ) {}

    public function schedule(Organization $organization, User $user, array $input): array
    {
        try {
            return DB::transaction(function () use ($organization, $user, $input): array {
                Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
                $account = OrganizationCommercialAccount::query()
                    ->where('organization_id', $organization->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->selfServiceGuard->assertCanMutate($account);

                $existing = CommercialContourChange::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('client_idempotency_key', (string) $input['client_idempotency_key'])
                    ->first();

                if ($existing !== null) {
                    $this->assertSameRequest($existing, $input);

                    return $this->payload($existing) + ['_created' => false];
                }

                if ($account->status->value === 'grace') {
                    throw new CommercialBillingConflictException('Commercial contour cannot change during grace.');
                }
                if ($account->current_period_start_at === null || $account->current_period_end_at === null) {
                    throw new InvalidArgumentException('A paid commercial period is required.');
                }

                $this->calculator->assertCurrentQuoteVersion((int) $input['quote_version']);
                $current = $this->billing->currentPackageSlugs((int) $organization->getKey());
                $quote = $this->calculator->preview(
                    $input['target_package_slugs'],
                    $current,
                    (bool) $input['full_suite'],
                    currentPeriodStartAt: $account->current_period_start_at,
                    currentPeriodEndAt: $account->current_period_end_at,
                );

                if ((bool) $input['full_suite']
                    || $quote['added_package_slugs'] !== []
                    || $quote['removed_package_slugs'] === []
                    || (int) $quote['amount_due_now_minor'] !== 0) {
                    throw new InvalidArgumentException('Only a reduced commercial contour can be scheduled.');
                }

                if (CommercialContourChange::query()
                    ->where('commercial_account_id', $account->getKey())
                    ->where('apply_at', $account->current_period_end_at)
                    ->where('status', 'scheduled')
                    ->exists()) {
                    throw new CommercialBillingConflictException('A contour change is already scheduled.');
                }

                $change = CommercialContourChange::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'organization_id' => $organization->getKey(),
                    'commercial_account_id' => $account->getKey(),
                    'user_id' => $user->getKey(),
                    'status' => 'scheduled',
                    'offer_type' => $quote['offer_type'],
                    'quote_version' => $quote['quote_version'],
                    'target_package_slugs' => $quote['target_package_slugs'],
                    'current_package_slugs' => $quote['current_package_slugs'],
                    'apply_at' => $account->current_period_end_at,
                    'client_idempotency_key' => (string) $input['client_idempotency_key'],
                ]);

                OrganizationPackageSubscription::query()
                    ->where('organization_id', $organization->getKey())
                    ->whereIn('access_source', ['paid_package', 'full_suite'])
                    ->whereIn('package_slug', $quote['removed_package_slugs'])
                    ->update([
                        'status' => 'scheduled_for_removal',
                        'cancel_at' => $account->current_period_end_at,
                    ]);

                return $this->payload($change) + ['_created' => true];
            }, 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (in_array($sqlState, ['23000', '23505', '40001', '40P01'], true)) {
                throw new CommercialBillingConflictException('A contour change is already scheduled.', 0, $exception);
            }

            throw $exception;
        }
    }

    private function assertSameRequest(CommercialContourChange $change, array $input): void
    {
        $target = array_values($input['target_package_slugs']);
        sort($target);
        $stored = $change->target_package_slugs;
        sort($stored);

        if ($target !== $stored
            || (bool) $input['full_suite'] !== ($change->offer_type->value === 'full_suite')
            || (int) $input['quote_version'] !== $change->quote_version) {
            throw new CommercialBillingConflictException('Idempotency key belongs to another contour change.');
        }
    }

    private function payload(CommercialContourChange $change): array
    {
        return [
            'change_id' => $change->public_id,
            'status' => $change->status,
            'offer_type' => $change->offer_type->value,
            'target_package_slugs' => $change->target_package_slugs,
            'current_package_slugs' => $change->current_package_slugs,
            'apply_at' => $change->apply_at->toJSON(),
        ];
    }
}
