<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestInProgress;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditQuote;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AssistantRequestLifecycle
{
    private const LEASE_MINUTES = 8;

    public function __construct(
        private readonly AICreditService $credits,
        private readonly AIPermissionChecker $permissions,
        private readonly ConversationManager $conversations,
        private readonly AssistantDataAccessPolicy $dataAccess,
    ) {}

    public function startQueued(Organization $organization, User $actor, ?int $conversationId, array $payload, string $surface): array
    {
        return $this->start($organization, $actor, $conversationId, $payload, $surface, true);
    }

    public function start(Organization $organization, User $actor, ?int $conversationId, array $payload, ?string $surface = null, bool $queued = false): array
    {
        if (!$this->permissions->canUseAssistant($actor, (int) $organization->id)) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }
        if ($conversationId !== null && $this->conversations->findAccessibleConversation($conversationId, $actor, (int) $organization->id, true) === null) {
            throw new AuthorizationException(trans_message('ai_assistant.conversation_not_found'));
        }

        $requestId = (string) ($payload['request_id'] ?? Str::uuid());
        if (!Str::isUuid($requestId)) {
            throw new RuntimeException(trans_message('ai_assistant.request_id_invalid'));
        }
        unset($payload['async']);
        $payload['request_id'] = $requestId;
        $payload['conversation_id'] = $conversationId;
        $payload['profile'] ??= 'normal';
        $requestHash = $this->credits->canonicalAssistantRequest($payload);

        return DB::transaction(function () use ($organization, $actor, $conversationId, $payload, $requestId, $requestHash, $surface, $queued): array {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $existing = AssistantRequest::query()->where('organization_id', $organization->id)->where('request_id', $requestId)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->user_id !== (int) $actor->id || !hash_equals($existing->request_hash, $requestHash)
                    || ($surface !== null && $existing->surface !== $surface)) {
                    throw new AuthorizationException(trans_message('ai_assistant.request_mismatch'));
                }
                if ($existing->conversation_id !== null && $this->conversations->findAccessibleConversation($existing->conversation_id, $actor, (int) $organization->id, true) === null) {
                    throw new AuthorizationException(trans_message('ai_assistant.conversation_not_found'));
                }
                if ($existing->status === 'completed' && is_array($existing->response)) {
                    $this->assertResponseAccess($existing->response, $actor, (int) $organization->id);
                    return ['request' => $existing, 'response' => $existing->response, 'created' => false];
                }
                if ($existing->status === 'running' && $existing->cancel_requested_at === null && $existing->lease_expires_at->isFuture()) {
                    if ($queued && $surface !== null && $existing->surface === $surface && is_array($existing->payload)) {
                        return ['request' => $existing, 'response' => null, 'created' => false];
                    }
                    throw new AssistantRequestInProgress();
                }
                if ($existing->status === 'running') {
                    $this->terminate($existing, 'cancelled', 'request_expired');
                }
                throw new AssistantRequestCancelled();
            }

            if ($conversationId !== null && AssistantRequest::query()->where('organization_id', $organization->id)
                ->where('conversation_id', $conversationId)->where('status', 'running')->exists()) {
                throw new AssistantRequestInProgress();
            }
            $quoteId = (string) ($payload['quote_id'] ?? '');
            if ($quoteId === '') {
                if ((bool) config('ai-assistant-credits.enforce', false)) {
                    throw new RuntimeException(trans_message('ai_assistant.quote_required'));
                }
                $quote = $this->credits->quote($organization, $actor, $payload);
                $quoteId = (string) $quote['quote_id'];
            }
            $reservation = $this->credits->begin($organization, $actor, $quoteId, $requestId, $conversationId === null ? null : (string) $conversationId, $payload);
            $limits = $this->credits->limits($reservation);
            $request = AssistantRequest::query()->create([
                'request_id' => $requestId,
                'organization_id' => $organization->id,
                'user_id' => $actor->id,
                'conversation_id' => $conversationId,
                'reservation_id' => $reservation->id,
                'request_hash' => $requestHash,
                'payload' => $queued ? $payload : null,
                'surface' => $surface,
                'profile' => (string) $payload['profile'],
                'status' => 'running',
                'stage' => 'queued',
                'calls_used' => 0,
                'max_calls' => (int) ($limits['max_calls'] ?? $limits['calls'] ?? 4),
                'approved_max_minor' => $this->credits->approvedMaximumMinor($reservation),
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES),
            ]);

            return ['request' => $request, 'response' => null, 'created' => true];
        }, 3);
    }

    public function bindConversation(AssistantRequest $request, Conversation $conversation, User $actor): void
    {
        $this->assertActor($request, $actor);
        if ((int) $conversation->organization_id !== $request->organization_id || $this->conversations->findAccessibleConversation((int) $conversation->id, $actor, $request->organization_id, true) === null) {
            throw new AuthorizationException(trans_message('ai_assistant.conversation_not_found'));
        }
        $request->forceFill(['conversation_id' => $conversation->id])->save();
        AICreditReservation::query()->whereKey($request->reservation_id)->update(['conversation_id' => (string) $conversation->id]);
    }

    public function claimQueued(int $id): ?AssistantRequest
    {
        return DB::transaction(function () use ($id): ?AssistantRequest {
            $request = AssistantRequest::query()->whereKey($id)->lockForUpdate()->first();
            if ($request === null || $request->status !== 'running' || $request->stage !== 'queued' || $request->started_at !== null) {
                return null;
            }
            if ($request->cancel_requested_at !== null || $request->lease_expires_at->isPast()) {
                $this->terminate($request, 'cancelled', 'request_expired');
                return null;
            }
            $actor = User::query()->find($request->user_id);
            if ($actor === null || !is_array($request->payload) || !in_array($request->surface, ['admin', 'lk', 'mobile'], true)) {
                $this->terminate($request, 'failed', 'request_invalid');
                return null;
            }
            try {
                $this->assertActor($request, $actor);
            } catch (AuthorizationException) {
                $this->terminate($request, 'failed', 'access_revoked');
                return null;
            }
            $request->forceFill(['started_at' => now(), 'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES)])->save();
            return $request;
        }, 3);
    }

    public function stage(AssistantRequest $request, User $actor, string $stage): void
    {
        $this->assertActive($request, $actor);
        $request->forceFill(['stage' => $stage, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES)])->save();
    }

    public function beforeProviderCall(AssistantRequest $request, User $actor, int $inputTokens, int $outputTokens): int
    {
        return DB::transaction(function () use ($request, $actor, $inputTokens, $outputTokens): int {
            $current = AssistantRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($current, $actor);
            $reservation = $this->reservation($current);
            $spent = $this->credits->successfulCostMicroRub($reservation);
            $next = $this->credits->costMicroRub($inputTokens, $outputTokens, $reservation);
            if ($current->calls_used >= $current->max_calls || $spent + $next > $this->credits->approvedCostMicroRub($reservation)
                || in_array($current->error_code, ['token_calibration_unavailable', 'token_input_limit_exceeded'], true)) {
                throw new AssistantBudgetExceeded();
            }
            $attempt = $current->calls_used + 1;
            $current->forceFill(['calls_used' => $attempt, 'stage' => 'generating', 'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes(self::LEASE_MINUTES)])->save();
            $request->setRawAttributes($current->getAttributes(), true);
            return $attempt;
        }, 3);
    }

    public function recordProviderUsage(AssistantRequest $request, array $response, int $attempt, bool $successful = true): void
    {
        $reservation = $this->reservation($request);
        $usageAvailable = ($response['provider_usage_available'] ?? true) !== false && isset($response['input_tokens'], $response['output_tokens']);
        $input = $usageAvailable ? max(0, (int) $response['input_tokens']) : 0;
        $output = $usageAvailable ? max(0, (int) $response['output_tokens']) : 0;
        $cost = $this->credits->costMicroRub($input, $output, $reservation);
        $this->credits->recordProviderCost($reservation, $cost, (string) ($response['provider'] ?? config('ai-assistant.llm.provider', 'timeweb')), (string) ($response['model'] ?? 'openai/gpt-6-luna'), 'assistant_chat', [
            'usage_key' => $request->request_id.':call:'.$attempt,
            'request_id' => $request->request_id,
            'attempt' => $attempt,
            'provider_usage_available' => $usageAvailable,
            'cost_available' => $usageAvailable,
            'usage_source' => $usageAvailable ? ($response['usage_source'] ?? 'provider_response') : 'unavailable',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'token_calibration' => $response['token_calibration'] ?? null,
        ], $successful);
        $calibration = $response['token_calibration'] ?? null;
        if (is_array($calibration) && (($calibration['persisted'] ?? true) === false || ($calibration['profile_input_exceeded'] ?? false))) {
            $request->forceFill(['error_code' => ($calibration['profile_input_exceeded'] ?? false) ? 'token_input_limit_exceeded' : 'token_calibration_unavailable'])->save();
        }
    }

    public function limits(AssistantRequest $request): array
    {
        return $this->credits->limits($this->reservation($request));
    }

    public function complete(AssistantRequest $request, User $actor, array $response, bool $useful = true, ?callable $onPublished = null): array
    {
        return DB::transaction(function () use ($request, $actor, $response, $useful, $onPublished): array {
            $current = AssistantRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertActive($current, $actor);
            $this->assertResponseAccess($response, $actor, $current->organization_id);
            $reservation = $this->reservation($current);
            $projected = $useful ? $this->credits->calculatedChargeMinor($reservation) : 0;
            $charged = $this->credits->finalize($reservation, 0, $useful);
            if (isset($response['message']['id'])) {
                $message = Message::query()->whereKey($response['message']['id'])->where('conversation_id', $current->conversation_id)
                    ->where('role', 'assistant')->where('metadata->request_id', $current->request_id)
                    ->where('metadata->actor_user_id', $actor->id)->lockForUpdate()->firstOrFail();
                $metadata = $message->metadata ?? [];
                $metadata['request_state'] = 'completed';
                $message->forceFill(['metadata' => $metadata])->save();
                $response['message']['metadata'] = $metadata;
            }
            $balance = $this->credits->balance(Organization::query()->findOrFail($current->organization_id));
            $response['request_id'] = $current->request_id;
            $response['conversation_id'] = $current->conversation_id;
            $response['status'] = 'completed';
            $response['credit_usage'] = [
                'charged_minor' => $charged,
                'projected_charge_minor' => $projected,
                'reserved_minor' => $reservation->reserved_minor,
                'available_after_minor' => (int) $balance['available_minor'],
                'charging_enabled' => (bool) config('ai-assistant-credits.enforce', false),
            ];
            $current->forceFill(['status' => 'completed', 'stage' => 'completed', 'response' => $response, 'completed_at' => now(), 'heartbeat_at' => now(), 'lease_expires_at' => now()])->save();
            if ($onPublished !== null) {
                $onPublished();
            }
            return $response;
        }, 3);
    }

    public function fail(AssistantRequest $request, string $errorCode = 'request_failed'): void
    {
        DB::transaction(function () use ($request, $errorCode): void {
            $current = AssistantRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if ($current !== null && $current->status === 'running') {
                $this->terminate($current, $current->cancel_requested_at !== null ? 'cancelled' : 'failed', $errorCode);
            }
        }, 3);
    }

    public function status(string $requestId, User $actor, int $organizationId, ?string $surface = null): array
    {
        if (!AssistantRequest::query()->where('request_id', $requestId)->where('organization_id', $organizationId)->where('user_id', $actor->id)->exists()) {
            $quote = $this->ownedQuote($requestId, $actor, $organizationId);
            return ['request_id' => $requestId, 'conversation_id' => null, 'status' => 'queued', 'stage' => 'queued', 'calls_used' => 0, 'max_calls' => (int) ($quote->limits['max_calls'] ?? 0)];
        }
        $request = $this->ownedRequest($requestId, $actor, $organizationId);
        $this->assertSurface($request, $surface);
        $status = ['request_id' => $request->request_id, 'conversation_id' => $request->conversation_id, 'status' => $request->cancel_requested_at !== null && $request->status === 'running' ? 'cancel_requested' : $request->status, 'stage' => $request->stage, 'calls_used' => $request->calls_used, 'max_calls' => $request->max_calls];
        if ($request->status === 'completed' && $surface !== null && $request->surface === $surface && is_array($request->response)) {
            $this->assertResponseAccess($request->response, $actor, $organizationId);
            $status['response'] = $request->response;
        }
        if (in_array($request->status, ['failed', 'cancelled'], true)) {
            $status['error_code'] = $request->error_code;
        }
        return $status;
    }

    public function cancel(string $requestId, User $actor, int $organizationId, ?string $surface = null): array
    {
        DB::transaction(function () use ($requestId, $actor, $organizationId, $surface): void {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $request = AssistantRequest::query()->where('request_id', $requestId)->where('organization_id', $organizationId)->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($request === null) {
                $quote = $this->ownedQuote($requestId, $actor, $organizationId);
                AssistantRequest::query()->create([
                    'request_id' => $requestId, 'organization_id' => $organizationId, 'user_id' => $actor->id,
                    'request_hash' => $quote->request_hash, 'profile' => $quote->profile,
                    'status' => 'cancelled', 'stage' => 'cancelled', 'max_calls' => (int) ($quote->limits['max_calls'] ?? 0),
                    'approved_max_minor' => $quote->max_units_minor, 'cancel_requested_at' => now(), 'completed_at' => now(),
                    'heartbeat_at' => now(), 'lease_expires_at' => now(),
                ]);
                return;
            }
            $this->assertActor($request, $actor);
            $this->assertSurface($request, $surface);
            if ($request->status === 'running' && $request->cancel_requested_at === null) {
                if ($request->stage === 'queued' && $request->started_at === null) {
                    $request->forceFill(['cancel_requested_at' => now()])->save();
                    $this->terminate($request, 'cancelled', 'request_cancelled');
                } else {
                    $request->forceFill(['cancel_requested_at' => now()])->save();
                }
            }
        }, 3);
        return $this->status($requestId, $actor, $organizationId, $surface);
    }

    public function expireAbandoned(int $batch = 100): int
    {
        $count = 0;
        foreach (AssistantRequest::query()->where('status', 'running')->where('lease_expires_at', '<=', now())->orderBy('id')->limit($batch)->get() as $request) {
            DB::transaction(function () use ($request, &$count): void {
                $current = AssistantRequest::query()->whereKey($request->id)->lockForUpdate()->first();
                if ($current !== null && $current->status === 'running' && $current->lease_expires_at->isPast()) {
                    $this->terminate($current, 'cancelled', 'request_expired');
                    $count++;
                }
            }, 3);
        }
        return $count;
    }

    public function purgeExpiredPayloads(int $batch = 100): int
    {
        return AssistantRequest::query()->whereNotNull('payload')->where('status', '!=', 'running')
            ->where('created_at', '<=', now()->subDays(90))->orderBy('id')->limit($batch)
            ->get()->each(fn (AssistantRequest $request) => $request->forceFill(['payload' => null])->save())->count();
    }

    private function assertActive(AssistantRequest $request, User $actor): void
    {
        $request->refresh();
        $this->assertActor($request, $actor);
        if ($request->status !== 'running' || $request->cancel_requested_at !== null || $request->lease_expires_at->isPast()) {
            throw new AssistantRequestCancelled();
        }
    }

    private function assertActor(AssistantRequest $request, User $actor): void
    {
        if ($request->user_id !== (int) $actor->id || !$actor->is_active || (int) $actor->current_organization_id !== $request->organization_id
            || !$actor->belongsToOrganization($request->organization_id) || !$this->permissions->canUseAssistant($actor, $request->organization_id)
            || ($request->conversation_id !== null && $this->conversations->findAccessibleConversation($request->conversation_id, $actor, $request->organization_id, true) === null)) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }
    }

    private function assertSurface(AssistantRequest $request, ?string $surface): void
    {
        if ($surface !== null && $request->surface !== null && $request->surface !== $surface) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }
    }

    private function ownedRequest(string $requestId, User $actor, int $organizationId, bool $lock = false): AssistantRequest
    {
        $query = AssistantRequest::query()->where('request_id', $requestId)->where('organization_id', $organizationId)->where('user_id', $actor->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $request = $query->firstOrFail();
        $this->assertActor($request, $actor);
        return $request;
    }

    private function ownedQuote(string $requestId, User $actor, int $organizationId): AICreditQuote
    {
        if (!$this->permissions->canUseAssistant($actor, $organizationId)) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }
        return AICreditQuote::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
            ->where('request_key', $requestId)->where('expires_at', '>', now())->orderByDesc('id')->firstOrFail();
    }

    private function assertResponseAccess(array $response, User $actor, int $organizationId): void
    {
        $refs = $response['message']['metadata']['source_refs'] ?? [];
        if (!is_array($refs)) {
            throw new AuthorizationException(trans_message('ai_assistant.sources_access_revoked'));
        }
        foreach ($refs as $ref) {
            if (!is_array($ref) || !$this->dataAccess->canReadReference($actor, $organizationId, $ref)) {
                throw new AuthorizationException(trans_message('ai_assistant.sources_access_revoked'));
            }
        }
    }

    private function reservation(AssistantRequest $request): AICreditReservation
    {
        return AICreditReservation::query()->whereKey($request->reservation_id)->firstOrFail();
    }

    private function terminate(AssistantRequest $request, string $status, string $errorCode): void
    {
        $this->credits->cancel($this->reservation($request));
        Message::query()->where('conversation_id', $request->conversation_id)->where('role', 'assistant')
            ->where('metadata->request_id', $request->request_id)->where('metadata->request_state', 'pending')->delete();
        $request->forceFill(['status' => $status, 'stage' => $status, 'error_code' => $errorCode, 'completed_at' => now(), 'lease_expires_at' => now()])->save();
    }
}
