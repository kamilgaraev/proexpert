<?php

declare(strict_types=1);

namespace App\Services\Credits;

use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditQuote;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditReservationAllocation;
use App\Models\Credits\AICreditWallet;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AICreditService
{
    /** @param array<string, mixed> $request */
    public function quote(Organization $organization, User $user, array $request): array
    {
        $this->assertMember($organization, $user);
        if (($request['attachment_ids'] ?? []) !== []) {
            $request = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantChatAttachmentService::class)->prepareRequest($request, $user, (int) $organization->id);
        }
        $profile = (string) ($request['profile'] ?? 'normal');
        $requestKey = trim((string) ($request['request_id'] ?? $request['request_key'] ?? ''));
        if ($requestKey === '' || strlen($requestKey) > 100) {
            throw new DomainException('AI credit quote requires a valid request key.');
        }
        $profiles = (array) config('ai-assistant-credits.profiles', []);
        $limits = $profiles[$profile] ?? null;
        if (! is_array($limits)) {
            throw new DomainException('Unknown AI credit profile.');
        }
        $greeting = $profile !== 'ocr' && $this->isStandaloneGreeting($request);
        if ($profile === 'ocr') {
            $pageCount = $request['page_count'] ?? null;
            if (! is_int($pageCount) || $pageCount < 1 || $pageCount > 10_000) { throw new DomainException('Invalid OCR page count.'); }
            $limits['max_calls'] = $pageCount;
        } elseif ($greeting) {
            $limits['input_tokens'] = 0;
            $limits['output_tokens'] = 0;
            $limits['max_calls'] = 0;
        }
        $requestHash = $this->canonicalAssistantRequest($request);
        $existing = AICreditQuote::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('request_key', $requestKey)
            ->where('request_hash', $requestHash)
            ->where('expires_at', '>', now())
            ->when($greeting, static fn ($query) => $query->where('max_units_minor', 0))
            ->latest('id')
            ->first();
        if ($existing !== null) {
            return $this->quotePayload($existing);
        }

        $pricing = $this->currentPricing();
        $quote = AICreditQuote::query()->create([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'request_key' => $requestKey,
            'request_hash' => $requestHash,
            'profile' => $profile,
            'limits' => $limits,
            'pricing' => $pricing,
            'price_version' => (int) config('ai-assistant-credits.price_version', 1),
            'min_units_minor' => $greeting ? 0 : $pricing['minimum_minor'],
            'max_units_minor' => $greeting ? 0 : $this->maximumProfileUnitsMinor($limits, $pricing),
            'expires_at' => now()->addSeconds((int) config('ai-assistant-credits.quote_ttl_seconds', 300)),
        ]);

        return $this->quotePayload($quote);
    }

    /** @param array<string, mixed> $request */
    public function begin(Organization $organization, User $user, string $quoteId, string $requestId, ?string $conversationId = null, array $request = []): AICreditReservation
    {
        $this->assertMember($organization, $user);
        if ($request === []) { throw new DomainException('AI credit request is required.'); }
        return DB::transaction(function () use ($organization, $user, $quoteId, $requestId, $conversationId, $request): AICreditReservation {
            if (($request['attachment_ids'] ?? []) !== []) {
                if ((isset($request['conversation_id']) ? (string) $request['conversation_id'] : null) !== $conversationId) {
                    throw new DomainException('AI credit attachment conversation conflict.');
                }
                $request['request_id'] = $requestId;
                $request = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantChatAttachmentService::class)->prepareRequest($request, $user, (int) $organization->id, true);
            }
            $wallet = $this->walletForUpdate((int) $organization->getKey());
            $existing = AICreditReservation::query()
                ->where('organization_id', $organization->getKey())
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $quote = AICreditQuote::query()->whereKey($existing->ai_credit_quote_id)->first();
                if ((int) $existing->user_id !== (int) $user->getKey() || $quote === null || $quote->public_id !== $quoteId
                    || ($request !== [] && ! hash_equals($quote->request_hash, $this->canonicalAssistantRequest($request)))) {
                    throw new DomainException('AI credit reservation idempotency conflict.');
                }
                return $existing;
            }
            $quote = AICreditQuote::query()->where('public_id', $quoteId)->lockForUpdate()->firstOrFail();
            if ((int) $quote->organization_id !== (int) $organization->getKey() || (int) $quote->user_id !== (int) $user->getKey()
                || $quote->request_key !== $requestId
                || $quote->expires_at->isPast() || ($request !== [] && ! hash_equals($quote->request_hash, $this->canonicalAssistantRequest($request)))) {
                throw new DomainException('AI credit quote is invalid or expired.');
            }
            $this->expireLots($wallet);
            $required = (bool) config('ai-assistant-credits.enforce', false)
                && !($quote->profile !== 'ocr' && $this->isStandaloneGreeting($request))
                ? (int) $quote->max_units_minor : 0;
            if ($wallet->availableMinor() < $required) {
                throw new DomainException('Insufficient AI credits.');
            }
            $lots = AICreditLot::query()
                ->where('organization_id', $organization->getKey())
                ->where('remaining_minor', '>', 0)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByRaw('expires_at nulls last')->orderBy('id')->lockForUpdate()->get();
            $reservation = AICreditReservation::query()->create([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->getKey(), 'user_id' => $user->getKey(),
                'ai_credit_quote_id' => $quote->getKey(), 'request_id' => $requestId, 'conversation_id' => $conversationId,
                'reserved_minor' => $required, 'consumed_minor' => 0, 'status' => 'reserved',
            ]);
            $remaining = $required;
            foreach ($lots as $lot) {
                if ($remaining === 0) { break; }
                $amount = min($remaining, (int) $lot->remaining_minor);
                $lot->decrement('remaining_minor', $amount);
                AICreditReservationAllocation::query()->create(['ai_credit_reservation_id' => $reservation->getKey(), 'ai_credit_lot_id' => $lot->getKey(), 'reserved_minor' => $amount]);
                $remaining -= $amount;
            }
            if ($remaining !== 0) { throw new DomainException('AI credit lots are inconsistent.'); }
            $wallet->increment('reserved_minor', $required);
            $wallet->refresh();
            $this->appendJournal($wallet, 'reserve', 0, 'reservation', $reservation->public_id, 'reserve:'.$reservation->public_id);

            return $reservation;
        }, 3);
    }

    public function recordSuccessfulCost(AICreditReservation $reservation, int|float $costRub, string $provider = 'unknown', string $model = 'unknown', string $operation = 'completion'): int
    {
        $this->recordProviderCost($reservation, max(0, (int) round($costRub * 1_000_000)), $provider, $model, $operation, [], true);
        return $this->successfulChargeMinor($reservation);
    }

    public function recordProviderCost(AICreditReservation $reservation, int $costMicroRub, string $provider, string $model, string $operation, array $metadata = [], bool $successful = false): void
    {
        DB::transaction(function () use ($reservation, $costMicroRub, $provider, $model, $operation, $metadata, $successful): void {
            $current = AICreditReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            $key = isset($metadata['usage_key']) ? (string) $metadata['usage_key'] : null;
            if (! in_array($current->status, ['reserved', 'finalized', 'cancelled'], true)) { throw new DomainException('Invalid AI reservation status.'); }
            if ($current->status !== 'reserved' && ($key === null || $key === '')) { throw new DomainException('Late AI provider usage requires an idempotency key.'); }
            $available = ($metadata['provider_usage_available'] ?? $metadata['cost_available'] ?? true) !== false;
            if (! $available && $costMicroRub !== 0) { throw new DomainException('Unavailable provider usage cannot have a known cost.'); }
            $metadata['cost_available'] = $available;
            $metadata['cost_is_estimate'] = false;
            $metadata['reservation_status_at_record'] = $current->status;
            $metadata['late_provider_result'] = $current->status !== 'reserved';
            if ($key !== null) {
                $existing = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $current->getKey())->where('usage_key', $key)->first();
                if ($existing !== null) {
                    $existingAvailable = ($existing->metadata['provider_usage_available'] ?? $existing->metadata['cost_available'] ?? true) !== false;
                    $usageTokens = static function (array $value): array {
                        $usage = is_array($value['usage'] ?? null) ? $value['usage'] : [];
                        return [$value['input_tokens'] ?? $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null,
                            $value['output_tokens'] ?? $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null,
                            $value['total_tokens'] ?? $usage['total_tokens'] ?? null];
                    };
                    if ((int) $existing->cost_micro_rub !== max(0, $costMicroRub) || (bool) $existing->is_successful !== $successful || $existing->provider !== $provider || $existing->model !== $model || $existing->operation !== $operation || $existingAvailable !== $available || $usageTokens($existing->metadata ?? []) !== $usageTokens($metadata)) { throw new DomainException('AI provider usage idempotency conflict.'); }
                    return;
                }
            }
            AICreditProviderUsage::query()->create([
                'organization_id' => $current->organization_id, 'ai_credit_reservation_id' => $current->getKey(),
                'usage_key' => $key, 'provider' => $provider, 'model' => $model, 'operation' => $operation,
                'cost_micro_rub' => max(0, $costMicroRub), 'is_successful' => $successful, 'metadata' => $metadata, 'occurred_at' => now(),
            ]);
        }, 3);
    }

    public function costMicroRub(int $inputTokens, int $outputTokens, ?AICreditReservation $reservation = null): int
    {
        $pricing = $reservation === null ? (array) config('ai-assistant-credits.pricing', [])
            : (array) AICreditQuote::query()->whereKey($reservation->ai_credit_quote_id)->value('pricing');
        $input = $this->safeMultiply(max(0, $inputTokens), (int) ($pricing['input_micro_rub_per_million'] ?? 14_000_000));
        $output = $this->safeMultiply(max(0, $outputTokens), (int) ($pricing['output_micro_rub_per_million'] ?? 68_000_000));
        if ($input > PHP_INT_MAX - $output) { throw new DomainException('AI credit pricing overflow.'); }
        $numerator = $input + $output;
        return intdiv($numerator, 1_000_000) + ($numerator % 1_000_000 === 0 ? 0 : 1);
    }

    public function limits(AICreditReservation $reservation): array
    {
        return (array) AICreditQuote::query()->findOrFail($reservation->ai_credit_quote_id)->limits;
    }

    public function finalize(AICreditReservation $reservation, int $usedMinor = 0, bool $useful = true, bool $cancelled = false): int
    {
        return DB::transaction(function () use ($reservation, $useful, $cancelled): int {
            $wallet = $this->walletForUpdate((int) $reservation->organization_id);
            $current = AICreditReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($current->status, ['finalized', 'cancelled'], true)) { return (int) $current->consumed_minor; }
            if ($current->status !== 'reserved') { return 0; }
            $charge = $useful ? min($this->successfulChargeMinor($current), (int) $current->reserved_minor) : 0;
            /** @var Collection<int, AICreditReservationAllocation> $allocations */
            $allocations = $current->allocations()->with('lot')->orderBy('id')->lockForUpdate()->get();
            $remainingCharge = $charge;
            $expiredRelease = 0;
            foreach ($allocations as $allocation) {
                $allocated = (int) $allocation->reserved_minor;
                $consumed = min($allocated, $remainingCharge);
                $allocation->forceFill(['consumed_minor' => $consumed])->save();
                $release = $allocated - $consumed;
                if ($release > 0) {
                    $lot = AICreditLot::query()->whereKey($allocation->ai_credit_lot_id)->lockForUpdate()->firstOrFail();
                    if ($lot->expires_at !== null && $lot->expires_at->isPast()) { $expiredRelease += $release; }
                    else { $lot->increment('remaining_minor', $release); }
                }
                $remainingCharge -= $consumed;
            }
            $wallet->decrement('reserved_minor', (int) $current->reserved_minor);
            if ($charge + $expiredRelease > 0) { $wallet->decrement('balance_minor', $charge + $expiredRelease); }
            $wallet->refresh();
            $current->forceFill([
                'consumed_minor' => $charge,
                'status' => $cancelled ? 'cancelled' : 'finalized',
                'finalized_at' => $cancelled ? null : now(),
                'cancelled_at' => $cancelled ? now() : null,
            ])->save();
            $this->appendJournal($wallet, 'consume', -$charge, 'reservation', $current->public_id, 'consume:'.$current->public_id);
            if ($expiredRelease > 0) { $this->appendJournal($wallet, 'expire', -$expiredRelease, 'reservation', $current->public_id, 'expire-release:'.$current->public_id); }
            if ($charge < (int) $current->reserved_minor) { $this->appendJournal($wallet, 'release', 0, 'reservation', $current->public_id, 'release:'.$current->public_id); }

            return $charge;
        }, 3);
    }

    public function cancel(AICreditReservation $reservation): void
    {
        $this->finalize($reservation, 0, false, true);
    }

    public function grant(Organization $organization, int $unitsMinor, string $source, ?\DateTimeInterface $expiresAt, string $idempotencyKey, ?int $commercialOrderId = null, array $metadata = []): void
    {
        if ($unitsMinor <= 0 || ! in_array($source, ['subscription', 'purchase', 'refund', 'conversion'], true) || ($source === 'purchase' && $expiresAt !== null)) { throw new DomainException('Invalid AI credit grant.'); }
        DB::transaction(function () use ($organization, $unitsMinor, $source, $expiresAt, $idempotencyKey, $commercialOrderId, $metadata): void {
            $wallet = $this->walletForUpdate((int) $organization->getKey());
            $entry = AICreditLedgerEntry::query()->where('organization_id', $organization->getKey())->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($entry !== null) {
                $lot = AICreditLot::query()->whereKey($entry->reference_id)->firstOrFail();
                $expiry = $expiresAt === null ? null : \Carbon\CarbonImmutable::instance($expiresAt)->toAtomString();
                if ((int) $lot->original_minor !== $unitsMinor || $lot->source !== $source || $lot->expires_at?->toAtomString() !== $expiry || $lot->commercial_order_id !== $commercialOrderId) { throw new DomainException('AI credit grant idempotency conflict.'); }
                return;
            }
            $lot = AICreditLot::query()->create(['organization_id' => $organization->getKey(), 'source' => $source, 'original_minor' => $unitsMinor, 'remaining_minor' => $unitsMinor, 'expires_at' => $expiresAt, 'commercial_order_id' => $commercialOrderId, 'metadata' => $metadata]);
            $wallet->increment('balance_minor', $unitsMinor); $wallet->refresh();
            $this->appendJournal($wallet, 'grant', $unitsMinor, 'lot', (string) $lot->getKey(), $idempotencyKey, $metadata);
        }, 3);
    }

    /** @return array<string, int|bool|string|null|array> */
    public function balance(Organization $organization, ?User $user = null): array
    {
        return DB::transaction(function () use ($organization, $user): array {
            $wallet = $this->walletForUpdate((int) $organization->getKey());
            $this->expireLots($wallet);
            $lots = AICreditLot::query()->where('organization_id', $organization->getKey())->where('remaining_minor', '>', 0)->get();
            $included = (int) $lots->where('source', 'subscription')->sum('remaining_minor');
            $purchased = (int) $lots->whereIn('source', ['purchase', 'conversion', 'refund'])->sum('remaining_minor');
            $baseExpiry = $lots->where('source', 'subscription')->pluck('expires_at')->filter()->sort()->first();
            $packs = [];
            foreach ((array) config('ai-assistant-credits.packs', []) as $id => $pack) { $packs[] = ['id' => $id] + $pack; }
            $canManage = $user !== null && $this->canPurchase($organization, $user);
            $enabled = (bool) config('ai-assistant-credits.enforce', false);
            $purchasesEnabled = $this->creditPurchasesEnabled();
            return ['can_purchase' => $canManage && $purchasesEnabled, 'can_manage_billing' => $canManage,
                'pack_purchase_enabled' => $purchasesEnabled, 'billing_mode' => $enabled ? 'paid' : 'shadow',
                'included_minor' => $included, 'purchased_minor' => $purchased, 'reserved_minor' => (int) $wallet->reserved_minor,
                'available_minor' => $wallet->availableMinor(), 'total_minor' => (int) $wallet->balance_minor,
                'base_period_expires_at' => $baseExpiry?->toAtomString(), 'packs' => $packs, 'charging_enabled' => $enabled];
        }, 3);
    }

    public function history(Organization $organization, int $perPage = 20, ?User $user = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = AICreditLedgerEntry::query()->where('organization_id', $organization->getKey());
        if ($user !== null) {
            $this->assertMember($organization, $user);
            if (! $this->canPurchase($organization, $user)) {
                $query->where('reference_type', 'reservation')->whereIn('reference_id', AICreditReservation::query()->select('public_id')->where('organization_id', $organization->getKey())->where('user_id', $user->getKey()));
            }
        }
        return $query->latest('id')->paginate(min(100, max(1, $perPage)));
    }

    public function calculatedChargeMinor(AICreditReservation $reservation): int
    {
        return min($this->successfulChargeMinor($reservation), $this->approvedMaximumMinor($reservation));
    }

    public function approvedCostMicroRub(AICreditReservation $reservation): int
    {
        $pricing = $this->reservationPricing($reservation);
        $unitCost = (int) ($pricing['unit_cost_micro_rub'] ?? 180_000);
        $unitMinor = (int) ($pricing['unit_minor'] ?? 100);
        if ($unitCost < 1 || $unitMinor < 1) { throw new DomainException('Invalid AI credit pricing.'); }
        return intdiv($this->safeMultiply($this->approvedMaximumMinor($reservation), $unitCost), $unitMinor);
    }

    public function approvedMaximumMinor(AICreditReservation $reservation): int
    {
        return (int) AICreditQuote::query()->findOrFail($reservation->ai_credit_quote_id)->max_units_minor;
    }

    public function successfulCostMicroRub(AICreditReservation $reservation): int
    {
        return (int) AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->getKey())->where('is_successful', true)->sum('cost_micro_rub');
    }

    public function canPurchase(Organization $organization, User $user): bool
    {
        $organizationId = (int) $organization->getKey();
        if (! $user->is_active || (int) $user->current_organization_id !== $organizationId
            || ! User::query()->whereKey($user->getKey())->where('is_active', true)->where('current_organization_id', $organizationId)->exists()
            || ! DB::table('organization_user')->where('organization_id', $organizationId)->where('user_id', $user->getKey())->where('is_active', true)->exists()) { return false; }
        $account = \App\Models\OrganizationCommercialAccount::query()->where('organization_id', $organization->getKey())->first();
        if ($account?->status->value === 'corporate') { return false; }
        return app(\App\Domain\Authorization\Services\AuthorizationService::class)->canCurrent($user, 'billing.manage', ['organization_id' => $organizationId]);
    }

    public function purchase(Organization $organization, User $user, string $packId, ?string $requestId = null): array
    {
        if (!$this->canPurchase($organization, $user)) { throw new DomainException(trans_message('ai_assistant.access_denied')); }
        $this->assertCreditPurchasesEnabled();
        return app(\App\Services\Billing\CommercialCheckoutService::class)->checkoutCredits($organization, $user, $packId, $requestId ?? (string) Str::uuid());
    }

    public function creditPurchasesEnabled(): bool
    {
        try {
            $this->assertCreditPurchasesEnabled();
            return true;
        } catch (AICreditsNotReadyException) {
            return false;
        }
    }

    public function assertCreditPurchasesEnabled(): void
    {
        if (!(bool) config('ai-assistant-credits.enforce', false)) { throw new AICreditsNotReadyException; }
    }

    private function assertMember(Organization $organization, User $user): void
    {
        if (! $user->belongsToOrganization((int) $organization->getKey())) { throw new DomainException('AI credit organization access denied.'); }
    }

    private function walletForUpdate(int $organizationId): AICreditWallet
    {
        AICreditWallet::query()->insertOrIgnore(['organization_id' => $organizationId, 'balance_minor' => 0, 'reserved_minor' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return AICreditWallet::query()->where('organization_id', $organizationId)->lockForUpdate()->firstOrFail();
    }

    private function expireLots(AICreditWallet $wallet): void
    {
        $lots = AICreditLot::query()->where('organization_id', $wallet->organization_id)->where('remaining_minor', '>', 0)->whereNotNull('expires_at')->where('expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($lots as $lot) {
            $amount = (int) $lot->remaining_minor; $lot->update(['remaining_minor' => 0]); $wallet->decrement('balance_minor', $amount); $wallet->refresh();
            $this->appendJournal($wallet, 'expire', -$amount, 'lot', (string) $lot->getKey(), 'expire:'.$lot->getKey());
        }
    }

    private function appendJournal(AICreditWallet $wallet, string $type, int $amountMinor, string $referenceType, string $referenceId, string $idempotencyKey, array $metadata = []): void
    {
        AICreditLedgerEntry::query()->create(['organization_id' => $wallet->organization_id, 'type' => $type, 'amount_minor' => $amountMinor, 'balance_after_minor' => $wallet->balance_minor, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'idempotency_key' => $idempotencyKey, 'metadata' => $metadata]);
    }

    /** @param array<string, mixed> $limits */
    private function maximumProfileUnitsMinor(array $limits, array $pricing): int
    {
        $input = (int) ($limits['input_tokens'] ?? 0);
        $output = (int) ($limits['output_tokens'] ?? 0);
        $calls = (int) ($limits['max_calls'] ?? 0);
        $inputCost = $this->safeMultiply($input, (int) ($pricing['input_micro_rub_per_million'] ?? 0));
        $outputCost = $this->safeMultiply($output, (int) ($pricing['output_micro_rub_per_million'] ?? 0));
        if ($inputCost > PHP_INT_MAX - $outputCost) { throw new DomainException('AI credit pricing overflow.'); }
        $numerator = $this->safeMultiply($inputCost + $outputCost, $calls);
        $microRub = intdiv($numerator, 1_000_000) + ($numerator % 1_000_000 === 0 ? 0 : 1);
        return $this->chargeMinor($microRub, $pricing);
    }

    private function isStandaloneGreeting(array $request): bool
    {
        if (preg_match('/^\s*(?:привет|здравствуй(?:те)?|добрый\s+(?:день|вечер|утро))\s*[!.?]?\s*$/iu', (string) ($request['message'] ?? '')) !== 1
            || ! empty($request['conversation_id']) || ! empty($request['goal']) || ! empty($request['desired_mode'])
            || ! empty($request['allow_actions'])) {
            return false;
        }

        if (!empty($request['attachment_ids'])) { return false; }

        $context = $request['context'] ?? [];
        if (! is_array($context) || ! $this->hasOnlyImplicitProjectReference($context['entity_refs'] ?? [])
            || ! empty($context['period']) || ! empty($context['filters'])
            || ! empty($context['source_route']) || ! in_array($context['source_module'] ?? null, [null, 'ai-assistant'], true)) {
            return false;
        }

        $uiState = $context['ui_state'] ?? [];
        return is_array($uiState) && array_diff(array_keys($uiState), ['assistant_path']) === [];
    }

    private function hasOnlyImplicitProjectReference(mixed $references): bool
    {
        if (! is_array($references)) {
            return false;
        }

        if ($references === []) {
            return true;
        }

        return array_is_list($references) && count($references) === 1
            && is_array($references[0]) && ($references[0]['type'] ?? null) === 'project';
    }

    private function successfulChargeMinor(AICreditReservation $reservation): int
    {
        if (AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->getKey())
            ->where('is_successful', true)->where(static fn ($query) => $query
                ->where('metadata->provider_usage_available', false)->orWhere('metadata->cost_available', false)
                ->orWhere('metadata->cost_is_estimate', true))->exists()) {
            return 0;
        }
        return $this->chargeMinor($this->successfulCostMicroRub($reservation), $this->reservationPricing($reservation));
    }

    private function chargeMinor(int $costMicroRub, array $pricing): int
    {
        $unitCost = (int) ($pricing['unit_cost_micro_rub'] ?? 180_000);
        $unitMinor = (int) ($pricing['unit_minor'] ?? 100);
        $step = (int) ($pricing['charge_step_minor'] ?? 50);
        $minimum = (int) ($pricing['minimum_minor'] ?? 50);
        if ($unitCost < 1 || $unitMinor < 1 || $step < 1 || $minimum < 0 || $costMicroRub < 0) { throw new DomainException('Invalid AI credit pricing.'); }
        $numerator = $this->safeMultiply($costMicroRub, $unitMinor);
        $denominator = $this->safeMultiply($unitCost, $step);
        $steps = intdiv($numerator, $denominator) + ($numerator % $denominator === 0 ? 0 : 1);
        return max($minimum, $this->safeMultiply($steps, $step));
    }

    private function safeMultiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right > 0 && $left > intdiv(PHP_INT_MAX, $right))) { throw new DomainException('AI credit pricing overflow.'); }
        return $left * $right;
    }

    private function currentPricing(): array
    {
        $pricing = (array) config('ai-assistant-credits.pricing', []);
        $pricing['unit_cost_micro_rub'] = (int) round((float) config('ai-assistant-credits.rub_per_unit', 0.18) * 1_000_000);
        $pricing['unit_minor'] = (int) config('ai-assistant-credits.unit_minor', 100);
        $pricing['charge_step_minor'] = (int) config('ai-assistant-credits.charge_step_minor', 50);
        $pricing['minimum_minor'] = (int) config('ai-assistant-credits.minimum_units_minor', 50);
        $this->chargeMinor(0, $pricing);
        return $pricing;
    }

    private function reservationPricing(AICreditReservation $reservation): array
    {
        return (array) AICreditQuote::query()->findOrFail($reservation->ai_credit_quote_id)->pricing;
    }

    /** @param array<string, mixed> $request */
    public function canonicalAssistantRequest(array $request): string
    {
        unset($request['quote_id'], $request['request_id'], $request['request_key']);
        $attachments = $request['attachment_ids'] ?? [];
        if (is_array($attachments)) { $attachments = array_map('strtolower', $attachments); sort($attachments, SORT_STRING); }
        $manifest = $request['attachment_manifest'] ?? [];
        if (is_array($manifest)) { usort($manifest, static fn (array $a, array $b): int => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''))); }
        if (($request['profile'] ?? 'normal') !== 'ocr') {
            $request = [
                'message' => (string) ($request['message'] ?? ''),
                'conversation_id' => isset($request['conversation_id']) ? (string) $request['conversation_id'] : null,
                'profile' => (string) ($request['profile'] ?? 'normal'),
                'allow_actions' => (bool) ($request['allow_actions'] ?? false),
                'context' => $request['context'] ?? [],
                'goal' => $request['goal'] ?? null,
                'desired_mode' => $request['desired_mode'] ?? null,
            ];
            if ($attachments !== []) {
                $request['attachment_ids'] = $attachments;
                $request['attachment_manifest'] = $manifest;
            }
        }
        $this->sortRecursive($request);
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) { ksort($value); }
        foreach ($value as &$item) { if (is_array($item)) { $this->sortRecursive($item); } }
    }

    /** @return array<string, int|string> */
    private function quotePayload(AICreditQuote $quote): array { return ['quote_id' => $quote->public_id, 'min_units_minor' => $quote->min_units_minor, 'max_units_minor' => $quote->max_units_minor, 'expires_at' => $quote->expires_at->toAtomString(), 'profile' => $quote->profile, 'price_version' => $quote->price_version]; }
}
