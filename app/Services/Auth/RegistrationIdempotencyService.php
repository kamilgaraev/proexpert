<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\BusinessLogicException;
use App\Models\AuthRegistrationAttempt;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class RegistrationIdempotencyService
{
    public function execute(string $audience, string $key, array $fingerprintPayload, Closure $operation): array
    {
        $requestHash = $this->requestHash($fingerprintPayload);

        return DB::transaction(function () use ($audience, $key, $requestHash, $operation): array {
            $this->acquireTransactionLock($audience, $key);

            $attempt = AuthRegistrationAttempt::query()
                ->where('audience', $audience)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($attempt instanceof AuthRegistrationAttempt && $attempt->expires_at->isPast()) {
                $attempt->delete();
                $attempt = null;
            }

            if ($attempt instanceof AuthRegistrationAttempt) {
                if (!hash_equals($attempt->request_hash, $requestHash)) {
                    throw new BusinessLogicException(trans_message('auth.registration_idempotency_conflict'), 409);
                }

                if (in_array($attempt->status, ['completed', 'failed'], true) && is_array($attempt->response)) {
                    return $this->hydrateResult($attempt->response, true);
                }

                throw new BusinessLogicException(trans_message('auth.registration_in_progress'), 409);
            }

            $attempt = AuthRegistrationAttempt::query()->create([
                'audience' => $audience,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'expires_at' => now()->addHours((int) config('web_auth.registration.idempotency_ttl_hours', 24)),
            ]);

            $result = $operation();
            $stored = $this->storeResult($result);
            $attempt->update([
                'status' => ($result['success'] ?? false) === true ? 'completed' : 'failed',
                'user_id' => $stored['user_id'] ?? null,
                'response' => $stored,
            ]);
            $result['idempotent_replay'] = false;

            return $result;
        }, 3);
    }

    private function acquireTransactionLock(string $audience, string $key): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$audience, $key]);
    }

    private function requestHash(array $payload): string
    {
        $password = (string) ($payload['password'] ?? '');
        unset($payload['password'], $payload['password_confirmation']);
        $payload['password_fingerprint'] = hash_hmac('sha256', $password, (string) config('app.key'));
        $payload = $this->sortRecursively($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortRecursively($value);
            }
        }

        ksort($payload);

        return $payload;
    }

    private function storeResult(array $result): array
    {
        $stored = $result;
        $user = $result['user'] ?? null;
        $organization = $result['organization'] ?? null;
        $stored['user_id'] = $user instanceof User ? $user->id : null;
        $stored['organization_id'] = $organization instanceof Organization
            ? $organization->id
            : null;
        unset($stored['user'], $stored['organization'], $stored['idempotent_replay']);

        return $stored;
    }

    private function hydrateResult(array $stored, bool $replay): array
    {
        $result = $stored;
        $userId = $result['user_id'] ?? null;
        $organizationId = $result['organization_id'] ?? null;
        unset($result['user_id'], $result['organization_id']);

        if (is_int($userId)) {
            $result['user'] = User::query()->find($userId);
        }
        if (is_int($organizationId)) {
            $result['organization'] = Organization::query()->find($organizationId);
        }

        $result['idempotent_replay'] = $replay;

        return $result;
    }
}
