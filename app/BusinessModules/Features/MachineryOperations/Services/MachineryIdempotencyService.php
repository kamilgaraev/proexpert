<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryIdempotencyRecord;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class MachineryIdempotencyService
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): TModel  $operation
     * @return TModel
     */
    public function execute(
        int $organizationId,
        int $actorUserId,
        ?string $idempotencyKey,
        string $operationType,
        array $payload,
        Closure $operation,
    ): Model {
        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            return $operation();
        }
        if (mb_strlen($key) > 100) {
            throw new DomainException(trans_message('machinery_operations.errors.idempotency_key_invalid'));
        }

        $requestHash = hash('sha256', $this->canonicalJson($payload));

        return DB::transaction(function () use ($organizationId, $actorUserId, $key, $operationType, $requestHash, $operation): Model {
            DB::table('machinery_idempotency_records')->insertOrIgnore([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $key,
                'operation_type' => $operationType,
                'request_hash' => $requestHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = MachineryIdempotencyRecord::query()
                ->where('organization_id', $organizationId)
                ->where('actor_user_id', $actorUserId)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->operation_type !== $operationType || $record->request_hash !== $requestHash) {
                throw new DomainException(trans_message('machinery_operations.errors.idempotency_conflict'));
            }

            if ($record->response_type !== null && $record->response_id !== null) {
                $responseType = $record->response_type;
                if (! is_a($responseType, Model::class, true)) {
                    throw new DomainException(trans_message('machinery_operations.errors.idempotency_response_missing'));
                }

                /** @var Model|null $existing */
                $existing = $responseType::query()->find($record->response_id);
                if ($existing === null) {
                    throw new DomainException(trans_message('machinery_operations.errors.idempotency_response_missing'));
                }

                return $existing;
            }

            $result = $operation();
            $record->update([
                'response_type' => $result::class,
                'response_id' => $result->getKey(),
            ]);

            return $result;
        });
    }

    private function canonicalJson(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return (string) json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
