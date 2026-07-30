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
        if ($organizationId < 1
            || $ownerId < 1
            || ! $this->isCanonicalUlid($id)
            || ($reportCode !== null && preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID, ['fields' => ['cursor']]);
        }
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
            if (! is_array($p)
                || ! isset($p['organization_id'], $p['owner_id'], $p['created_at'], $p['id'], $p['expires_at'])
                || ! array_key_exists('report_code', $p)
                || ! is_int($p['organization_id'])
                || ! is_int($p['owner_id'])
                || ! is_string($p['report_code']) && $p['report_code'] !== null
                || ! is_string($p['created_at'])
                || ! is_string($p['id'])
                || ! is_string($p['expires_at'])
                || ! $this->isCanonicalUlid($p['id'])
                || ! $this->isCanonicalTimestamp($p['created_at'], 'Y-m-d\\TH:i:s.uP')
                || ! $this->isCanonicalTimestamp($p['expires_at'], DATE_ATOM)
                || $p['organization_id'] !== $organizationId
                || $p['owner_id'] !== $ownerId
                || $p['report_code'] !== $reportCode
                || new DateTimeImmutable($p['expires_at']) <= ($this->now ?? new DateTimeImmutable)) {
                throw new InvalidArgumentException;
            }

            return $p['created_at'].'|'.$p['id'];
        } catch (\Throwable) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID, ['fields' => ['cursor']]);
        }
    }

    private function isCanonicalUlid(string $id): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) === 1;
    }

    private function isCanonicalTimestamp(string $value, string $format): bool
    {
        $timestamp = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $timestamp instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $timestamp->format($format) === $value;
    }
}
