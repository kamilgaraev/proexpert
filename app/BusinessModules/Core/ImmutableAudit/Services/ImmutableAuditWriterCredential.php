<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use DomainException;

final class ImmutableAuditWriterCredential
{
    public function derive(string $secret): string
    {
        if (strlen($secret) < 32 || count(array_unique(str_split($secret))) < 12) {
            throw new DomainException('immutable_audit_writer_secret_not_configured');
        }

        return hash_hmac('sha256', 'immutable-audit-writer-v2', $secret);
    }

    public function fingerprint(string $secret): string
    {
        return hash('sha256', 'immutable-audit-writer-credential:'.$this->derive($secret));
    }
}
