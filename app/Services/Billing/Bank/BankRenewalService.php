<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Enums\Billing\CommercialBillingPeriod;
use App\Exceptions\Billing\CommercialCheckoutConflictException;
use App\Models\CommercialBankInvoice;
use App\Models\CommercialContourChange;
use App\Models\CommercialOrder;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Billing\CommercialBillingNotificationService;
use App\Services\Billing\CommercialOfferCalculator;
use App\Services\Billing\CommercialSelfServiceGuard;
use App\Services\Credits\AssistantRevenueAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class BankRenewalService
{
    public function __construct(private readonly BankInvoiceService $invoices, private readonly CommercialOfferCalculator $calculator) {}

    public function process(CarbonInterface $at, int $limit = 100): array
    {
        $counts = ['processed' => 0, 'created_cycles' => 0, 'created_attempts' => 0, 'canceled_changes' => 0, 'failed' => 0, 'suspended' => 0];
        $ready = app(TochkaClient::class)->ready();
        $now = CarbonImmutable::instance($at);
        $cursorKey = 'commercial:bank-renewal-account-cursor';
        $cursor = max(0, (int) Cache::get($cursorKey, 0));
        $query = OrganizationCommercialAccount::query()->whereIn('status', ['active', 'grace'])
            ->whereNotNull('current_period_end_at')->where('current_period_end_at', '<=', $now->addDays((int) config('bank_billing.renewal_notice_days', 7)))
            ->orderBy('id')->limit(max(1, min(500, $limit)));
        $accounts = (clone $query)->where('id', '>', $cursor)->get();
        if ($accounts->isEmpty() && $cursor > 0) {
            Cache::forever($cursorKey, 0);
            $accounts = $query->get();
        }
        foreach ($accounts as $account) {
            Cache::forever($cursorKey, (int) $account->id);
            try {
                if ($this->applyFreeChange($account, $now)) {
                    $counts['processed']++;

                    continue;
                }
                if ($ready && $account->bank_auto_invoice && ! $this->hasPendingPurchase($account)) {
                    $invoice = $this->create($account);
                    app(BankSettlementService::class)->activate((int) $invoice->id);
                    $account->refresh();
                    if ($account->current_period_end_at->greaterThan($now)) {
                        $this->remind($invoice, $account, $now);
                    }
                }
                $counts['processed']++;
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::error('Bank renewal processing failed.', ['account_id' => $account->id, 'exception' => $exception::class]);
            }
            if ($account->current_period_end_at->lessThanOrEqualTo($now)) {
                $this->lifecycle($account, $now);
            }
        }

        return $counts + ['ready' => $ready];
    }

    public function manual(Organization $organization, User $actor, string $key): array
    {
        app(BankBillingAccess::class)->organization($actor, (int) $organization->id, true);
        $account = OrganizationCommercialAccount::query()->where('organization_id', $organization->id)->firstOrFail();
        app(CommercialSelfServiceGuard::class)->assertCanMutate($account);
        if ($account->current_period_end_at === null || ! in_array($account->status->value, ['active', 'grace', 'suspended'], true)) {
            throw new InvalidArgumentException(trans_message('bank_billing.renewal_unavailable'));
        }
        $invoice = $this->create($account, $key);

        return app(\App\Services\Billing\CommercialManualPaymentService::class)
            ->paymentResponse($invoice->order, $invoice->payment, $invoice->wasRecentlyCreated, $invoice->expires_at)
            + ['payment_source' => 'tochka', 'bank_invoice' => $this->invoices->payload($invoice)];
    }

    public function create(OrganizationCommercialAccount $source, ?string $manualKey = null): CommercialBankInvoice
    {
        return DB::transaction(function () use ($source, $manualKey): CommercialBankInvoice {
            $account = OrganizationCommercialAccount::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            app(CommercialSelfServiceGuard::class)->assertCanMutate($account);
            $change = CommercialContourChange::query()->where('commercial_account_id', $account->id)
                ->where('organization_id', $account->organization_id)->where('status', 'scheduled')
                ->where('apply_at', '<=', $account->current_period_end_at)->lockForUpdate()->first();
            $open = CommercialBankInvoice::query()->whereHas('order', fn ($query) => $query->where('commercial_account_id', $account->id)
                ->where('kind', 'renewal')->where('period_start_at', '>=', $account->current_period_end_at)
                ->where('period_end_at', '>', $account->current_period_end_at))
                ->whereNull('activated_at')->whereIn('status', ['awaiting_payment', 'partially_paid', 'paid'])->latest('id')->first();
            if ($open !== null) {
                return $open;
            }
            $key = 'bank:'.$account->id.':'.($manualKey ?? $account->current_period_end_at->format('YmdHis')).':'.($change?->id ?? 0);
            if ($manualKey === null) {
                $generation = CommercialBankInvoice::query()->where('organization_id', $account->organization_id)
                    ->where('status', 'canceled')->whereNull('activated_at')
                    ->whereHas('order', fn ($query) => $query->where('commercial_account_id', $account->id)
                        ->where('kind', 'renewal')->where('period_start_at', '>=', $account->current_period_end_at)
                        ->where('period_end_at', '>', $account->current_period_end_at))->max('id');
                $key .= ':r'.($generation ?? 0);
            }
            $existing = CommercialOrder::query()->where('organization_id', $account->organization_id)->where('server_idempotency_key', $key)->first();
            if ($existing !== null) {
                return CommercialBankInvoice::query()->where('commercial_order_id', $existing->id)->firstOrFail();
            }
            if ($this->hasPendingPurchase($account)) {
                throw new CommercialCheckoutConflictException('A commercial order is awaiting payment.');
            }
            $this->invoices->assertReady();
            $current = $this->currentPackageSlugs($account);
            $slugs = $change?->target_package_slugs ?? $current;
            if ($slugs === [] || $account->responsible_user_id === null) {
                throw new InvalidArgumentException(trans_message('bank_billing.renewal_unavailable'));
            }
            $full = ($change?->offer_type ?? $account->offer_type)->value === 'full_suite';
            $now = CarbonImmutable::instance(now());
            $anchor = CarbonImmutable::instance($account->current_period_end_at);
            $start = $now->lessThan($anchor->addDays((int) config('bank_billing.grace_days', 7))) ? $anchor : $anchor->max($now);
            $term = CommercialBillingPeriod::from($account->billing_period ?? '30_days');
            $end = $term->endAt($start);
            $quote = $this->calculator->preview($slugs, $current, $full, $now, $account->current_period_start_at, $account->current_period_end_at,
                billingPeriod: $term->value, currentBillingPeriod: $term->value);
            $order = new CommercialOrder([
                'public_id' => (string) Str::uuid(), 'organization_id' => $account->organization_id,
                'commercial_account_id' => $account->id, 'user_id' => $account->responsible_user_id,
                'kind' => 'renewal', 'status' => 'pending_payment', 'offer_type' => $quote['offer_type'],
                'quote_version' => $quote['quote_version'], 'billing_period' => $term->value, 'selected_package_slugs' => $quote['target_package_slugs'],
                'current_package_slugs' => $current, 'selected_resource_addons' => [],
                'amount_minor' => $quote['period_total_minor'], 'amount' => $quote['period_total'], 'currency' => 'RUB',
                'period_start_at' => $start, 'period_end_at' => $end, 'auto_renew_consent' => false,
                'client_idempotency_key' => $key, 'server_idempotency_key' => $key,
            ]);
            $order->forceFill(['assistant_revenue_allocation' => app(AssistantRevenueAllocation::class)
                ->snapshot($quote, $now, $account->current_period_start_at, $account->current_period_end_at,
                    $quote['period_total_minor'], true, $start, $end)]);
            $order->save();
            $payment = $order->payments()->create(['provider' => 'tochka', 'role' => 'initial', 'attempt_number' => 1,
                'provider_status' => 'created', 'amount_minor' => $order->amount_minor, 'currency' => 'RUB',
                'provider_idempotency_key' => (string) Str::uuid(), 'payment_method_saved' => false]);
            $change?->forceFill(['commercial_order_id' => $order->id])->save();

            return $this->invoices->create($order, $payment);
        }, 3);
    }

    public function currentPackageSlugs(OrganizationCommercialAccount $account): array
    {
        if ($account->current_period_end_at === null) {
            return [];
        }

        return OrganizationPackageSubscription::query()->where('commercial_account_id', $account->id)
            ->where('organization_id', $account->organization_id)->whereIn('access_source', ['paid_package', 'full_suite'])
            ->whereIn('status', ['active', 'grace', 'expired', 'scheduled_for_removal'])
            ->where('current_period_end_at', '>=', $account->current_period_end_at)
            ->orderBy('package_slug')->pluck('package_slug')->unique()->values()->all();
    }

    private function hasPendingPurchase(OrganizationCommercialAccount $account): bool
    {
        return CommercialOrder::query()->where('organization_id', $account->organization_id)
            ->where('commercial_account_id', $account->id)->whereNotIn('kind', ['renewal', 'ai_credits'])
            ->where(fn ($query) => $query->where(fn ($pending) => $pending->where('status', 'pending_payment')
                ->whereHas('payments', fn ($payments) => $payments->where('provider', 'tochka')))
                ->orWhere(fn ($paid) => $paid->where('status', 'paid')->whereIn('id', CommercialBankInvoice::query()
                    ->select('commercial_order_id')->where('organization_id', $account->organization_id)->whereNull('activated_at'))))->exists();
    }

    private function applyFreeChange(OrganizationCommercialAccount $source, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($source, $now): bool {
            $account = OrganizationCommercialAccount::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $change = CommercialContourChange::query()->where('commercial_account_id', $account->id)
                ->where('organization_id', $account->organization_id)->where('status', 'scheduled')
                ->whereJsonLength('target_package_slugs', 0)->where('apply_at', '<=', $account->current_period_end_at)
                ->lockForUpdate()->first();
            if ($change === null) {
                return false;
            }
            if ($now->lessThan($change->apply_at)) {
                return true;
            }
            OrganizationPackageSubscription::query()->where('commercial_account_id', $account->id)
                ->where('organization_id', $account->organization_id)->whereIn('access_source', ['paid_package', 'full_suite'])
                ->update(['status' => 'expired', 'current_period_end_at' => $change->apply_at, 'cancel_at' => null, 'canceled_at' => $now]);
            $account->forceFill(['status' => 'free', 'offer_type' => 'packages', 'auto_renew_enabled' => false,
                'saved_payment_method_active' => false, 'bank_auto_invoice' => false, 'grace_started_at' => null, 'grace_ends_at' => null])->save();
            $change->forceFill(['status' => 'applied', 'applied_at' => $now])->save();
            app(AccessController::class)->clearAccessCache((int) $account->organization_id);

            return true;
        }, 3);
    }

    private function lifecycle(OrganizationCommercialAccount $source, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($source, $now): void {
            $account = OrganizationCommercialAccount::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($account->current_period_end_at->greaterThan($now)) {
                return;
            }
            $deadline = CarbonImmutable::instance($account->current_period_end_at)->addDays((int) config('bank_billing.grace_days', 7));
            $hasInvoice = CommercialBankInvoice::query()->where('organization_id', $account->organization_id)
                ->whereNull('activated_at')->whereIn('status', ['awaiting_payment', 'partially_paid', 'paid'])
                ->where('expires_at', '>=', $now)->whereHas('order', fn ($query) => $query
                ->where('commercial_account_id', $account->id)->where('kind', 'renewal')
                ->where('period_start_at', '>=', $account->current_period_end_at)
                ->where('period_end_at', '>', $account->current_period_end_at))->exists();
            $grace = ($account->bank_auto_invoice || $hasInvoice || $account->status->value === 'grace') && $now->lessThan($deadline);
            $account->forceFill(['status' => $grace ? 'grace' : 'suspended', 'auto_renew_enabled' => false,
                'saved_payment_method_active' => false, 'grace_started_at' => $account->current_period_end_at,
                'grace_ends_at' => $deadline])->save();
            OrganizationPackageSubscription::query()->where('commercial_account_id', $account->id)
                ->where('organization_id', $account->organization_id)->whereIn('access_source', ['paid_package', 'full_suite'])
                ->whereIn('status', ['active', 'grace', 'scheduled_for_removal'])->get()->each(fn ($row) => $row->forceFill([
                    'status' => $grace ? 'grace' : 'expired', 'current_period_end_at' => $grace ? $deadline : $account->current_period_end_at,
                ])->save());
        }, 3);
        app(AccessController::class)->clearAccessCache((int) $source->organization_id);
    }

    private function remind(CommercialBankInvoice $invoice, OrganizationCommercialAccount $account, CarbonImmutable $now): void
    {
        if ($invoice->paid_at !== null || ! in_array($invoice->status, ['awaiting_payment', 'partially_paid'], true)) {
            return;
        }
        $day = $now->setTimezone('Europe/Moscow')->toDateString();
        if ($invoice->last_reminded_at?->setTimezone('Europe/Moscow')->toDateString() === $day) {
            return;
        }
        app(CommercialBillingNotificationService::class)->notify($account, 'bank_invoice_'.$invoice->id.'_'.$day,
            'bank_billing.renewal_notice', ['invoice_id' => $invoice->public_id, 'invoice_number' => $invoice->number]);
        $invoice->forceFill(['last_reminded_at' => $now])->save();
    }
}
