<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Models\Organization;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class MobileMutationIdempotency
{
    public function run(
        int $organizationId,
        int $userId,
        ?string $key,
        string $operation,
        array $payload,
        callable $create,
        callable $find,
    ): Model {
        if ($key === null || $key === '') {
            return $create();
        }

        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key) !== 1) {
            throw new DomainException(trans_message('safety_management.errors.idempotency_key_invalid'), 422);
        }

        $fingerprint = hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($organizationId, $userId, $key, $operation, $fingerprint, $create, $find): Model {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $existing = DB::table('mobile_mutation_idempotencies')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                if ($existing->operation !== $operation || $existing->payload_fingerprint !== $fingerprint) {
                    throw new DomainException(trans_message('safety_management.errors.idempotency_conflict'), 409);
                }

                $result = $find((int) $existing->resource_id);
                if (! $result instanceof Model) {
                    throw new DomainException(trans_message('safety_management.errors.idempotency_conflict'), 409);
                }

                return $result;
            }

            $result = $create();
            if (! $result instanceof Model) {
                throw new DomainException(trans_message('safety_management.errors.store_failed'));
            }

            DB::table('mobile_mutation_idempotencies')->insert([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'idempotency_key' => $key,
                'operation' => $operation,
                'payload_fingerprint' => $fingerprint,
                'resource_id' => $result->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        });
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            $hash = hash_file('sha256', $value->getPathname());
            if ($hash === false) {
                throw new DomainException(trans_message('safety_management.errors.idempotency_key_invalid'), 422);
            }

            return ['file_sha256' => $hash];
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
