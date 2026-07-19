<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use DomainException;
use Illuminate\Database\ConnectionInterface;

final class ImmutableAuditWriterReadinessService
{
    public function __construct(
        private readonly ImmutableAuditWriterCredential $credential = new ImmutableAuditWriterCredential,
    ) {}

    /** @return array{ready:bool,phase:?string,reason:?string} */
    public function status(ConnectionInterface $connection, string $secret): array
    {
        try {
            $fingerprint = $this->credential->fingerprint($secret);
        } catch (DomainException) {
            return ['ready' => false, 'phase' => null, 'reason' => 'writer_secret_invalid'];
        }
        if (! $connection->getSchemaBuilder()->hasTable('immutable_audit_rollout')) {
            return ['ready' => false, 'phase' => null, 'reason' => 'rollout_not_installed'];
        }
        $row = $connection->table('immutable_audit_rollout')->where('singleton', true)->first();
        $phase = isset($row->phase) ? (string) $row->phase : null;
        if ($phase !== 'phase_b' || (int) ($row->writer_version ?? 0) !== ImmutableAuditRolloutService::PHASE_B_WRITER_VERSION) {
            return ['ready' => false, 'phase' => $phase, 'reason' => $phase ?? 'rollout_not_installed'];
        }
        if (! isset($row->writer_credential_hash) || ! hash_equals((string) $row->writer_credential_hash, $fingerprint)) {
            return ['ready' => false, 'phase' => $phase, 'reason' => 'writer_credential_mismatch'];
        }

        return ['ready' => true, 'phase' => $phase, 'reason' => null];
    }

    public function assertReady(ConnectionInterface $connection, string $secret): string
    {
        $status = $this->status($connection, $secret);
        if (! $status['ready']) {
            throw new DomainException('immutable_audit_writer_not_ready');
        }

        return $this->credential->derive($secret);
    }
}
