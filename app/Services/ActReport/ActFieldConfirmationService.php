<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Models\ActFieldConfirmation;
use App\Models\ContractPerformanceAct;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ActFieldConfirmationService
{
    public function __construct(private readonly ActFieldSignatureFileService $files) {}

    /** @return array<string, mixed> */
    public function confirm(
        ContractPerformanceAct $act,
        User $user,
        int $organizationId,
        string $encodedSignature,
        string $idempotencyKey
    ): array {
        $act->loadMissing('contract.organization');
        if (! $act->contract || (int) $act->contract->organization_id !== $organizationId) {
            throw new \DomainException(trans_message('act_reports.access_denied'), 404);
        }

        $signature = preg_replace('#^data:image/png;base64,#i', '', trim($encodedSignature));
        $bytes = base64_decode((string) $signature, true);
        if (! is_string($bytes) || strlen($bytes) < 64 || strlen($bytes) > 1_000_000
            || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('Некорректное изображение подписи.');
        }
        $hash = hash('sha256', $bytes);

        try {
            return DB::transaction(function () use ($act, $user, $organizationId, $bytes, $hash, $idempotencyKey): array {
                if (DB::getDriverName() === 'pgsql') {
                    $lockKeys = [
                        "act-field-confirmation-key:{$organizationId}:{$idempotencyKey}",
                        "act-field-confirmation-user:{$organizationId}:{$act->id}:{$user->id}",
                    ];
                    sort($lockKeys, SORT_STRING);
                    foreach ($lockKeys as $lockKey) {
                        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$lockKey]);
                    }
                }

                $existing = ActFieldConfirmation::query()
                    ->where('organization_id', $organizationId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    if ((int) $existing->act_id !== (int) $act->id
                        || (int) $existing->user_id !== (int) $user->id
                        || ! hash_equals((string) $existing->signature_sha256, $hash)) {
                        throw new \DomainException('Ключ идемпотентности уже использован для другой фиксации.', 409);
                    }

                    return ['data' => $this->present($existing), 'created' => false];
                }
                $userConfirmation = ActFieldConfirmation::query()
                    ->where('act_id', $act->id)
                    ->where('user_id', $user->id)
                    ->first();
                if ($userConfirmation) {
                    throw new \DomainException('Вы уже зафиксировали приёмку этого акта.', 409);
                }
                if ($act->status === ContractPerformanceAct::STATUS_ANNULLED) {
                    throw new \DomainException('По аннулированному акту нельзя зафиксировать приёмку.', 409);
                }

                $file = $this->files->store($act, $bytes, $user);
                try {
                    $confirmation = ActFieldConfirmation::query()->create([
                        'act_id' => (int) $act->id,
                        'organization_id' => $organizationId,
                        'user_id' => (int) $user->id,
                        'file_id' => (int) $file->id,
                        'idempotency_key' => $idempotencyKey,
                        'signature_sha256' => $hash,
                        'confirmed_at' => now(),
                    ]);
                } catch (\Throwable $exception) {
                    $this->files->deleteObject($file);
                    throw $exception;
                }

                return ['data' => $this->present($confirmation), 'created' => true];
            });
        } catch (QueryException $exception) {
            $existing = ActFieldConfirmation::query()
                ->where('organization_id', $organizationId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                if ((int) $existing->act_id !== (int) $act->id
                    || (int) $existing->user_id !== (int) $user->id
                    || ! hash_equals((string) $existing->signature_sha256, $hash)) {
                    throw new \DomainException('Ключ идемпотентности уже использован для другой фиксации.', 409, $exception);
                }

                return ['data' => $this->present($existing), 'created' => false];
            }
            if (ActFieldConfirmation::query()->where('act_id', $act->id)->where('user_id', $user->id)->exists()) {
                throw new \DomainException('Вы уже зафиксировали приёмку этого акта.', 409, $exception);
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function present(ActFieldConfirmation $confirmation): array
    {
        return [
            'id' => (int) $confirmation->id,
            'act_id' => (int) $confirmation->act_id,
            'file_id' => (int) $confirmation->file_id,
            'user_id' => (int) $confirmation->user_id,
            'confirmed_at' => $confirmation->confirmed_at->toISOString(),
            'evidence_type' => 'field_acceptance',
            'legal_signature' => false,
        ];
    }
}
