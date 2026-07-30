<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Cursors;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SignedReportSavedViewCursorCodec
{
    public function __construct(private string $key, private ?DateTimeImmutable $now = null) {}

    public function encode(int $organizationId, int $ownerId, DateTimeImmutable $createdAt, string $id, ?string $reportCode): string
    {
        $now = $this->now ?? new DateTimeImmutable;
        $p = ['organization_id' => $organizationId, 'owner_id' => $ownerId, 'created_at' => $createdAt->format('Y-m-d\\TH:i:s.uP'), 'id' => $id, 'report_code' => $reportCode, 'expires_at' => $now->modify('+1 hour')->format(DATE_ATOM)];
        $json = json_encode($p, JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encoded.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', 'report_saved_views|'.$encoded, $this->key, true)), '+/', '-_'), '=');
    }

    public function decode(string $token, int $organizationId, int $ownerId, ?string $reportCode): string
    {
        try {
            [$body,$sig] = explode('.', $token, 2);
            $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', 'report_saved_views|'.$body, $this->key, true)), '+/', '-_'), '=');
            if (! hash_equals($expected, $sig)) {
                throw new InvalidArgumentException;
            }$json = base64_decode(strtr($body, '-_', '+/').str_repeat('=', (4 - strlen($body) % 4) % 4), true);
            $p = is_string($json) ? json_decode($json, true, 16, JSON_THROW_ON_ERROR) : null;
            if (! is_array($p) || $p['organization_id'] !== $organizationId || $p['owner_id'] !== $ownerId || $p['report_code'] !== $reportCode || ! is_string($p['created_at'] ?? null) || ! is_string($p['id'] ?? null) || new DateTimeImmutable($p['expires_at']) <= ($this->now ?? new DateTimeImmutable)) {
                throw new InvalidArgumentException;
            }

return $p['created_at'].'|'.$p['id'];
        } catch (\Throwable) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID,['fields' => ['cursor']]);
        }
    }
}
