<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Cursors;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSubscriptionCursorCodec;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionCursor;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSubscriptionStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class SignedReportSubscriptionCursorCodec implements ReportSubscriptionCursorCodec
{
    public function __construct(private string $key, private ReportExecutionClock $clock) {}

    public function encode(ReportExecutionContext $context, ReportSubscriptionCursor $cursor): string
    {
        try {
            if ($cursor->organizationId !== $context->scope->organizationId || $cursor->ownerId !== $context->actor->id) {
                throw new InvalidArgumentException;
            }
            $json = CanonicalJson::encode($this->payload($cursor));
            $body = self::b64($json);

            return $body.'.'.self::b64(hash_hmac('sha256', 'report_subscriptions|'.$body, $this->key, true));
        } catch (Throwable $exception) {
            throw $this->invalid($exception);
        }
    }

    public function decode(ReportExecutionContext $context, ?ReportSubscriptionStatus $expectedStatusFilter, string $cursor): ReportSubscriptionCursor
    {
        try {
            [$body, $signature] = $this->segments($cursor);
            $expected = self::b64(hash_hmac('sha256', 'report_subscriptions|'.$body, $this->key, true));
            if (! hash_equals($expected, $signature)) {
                throw new InvalidArgumentException;
            }
            $json = self::unb64($body);
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || CanonicalJson::encode($payload) !== $json || ! $this->hasValidPayloadShape($payload)) {
                throw new InvalidArgumentException;
            }
            $last = $payload['last_next_run_at'] === null ? null : $this->timestamp($payload['last_next_run_at']);
            $decoded = new ReportSubscriptionCursor($payload['version'], $payload['organization_id'], $payload['owner_id'], $payload['status_filter'] === null ? null : ReportSubscriptionStatus::from($payload['status_filter']), $payload['order'], $last, $payload['last_id'], $this->timestamp($payload['expires_at']));
            if ($decoded->organizationId !== $context->scope->organizationId || $decoded->ownerId !== $context->actor->id || $decoded->statusFilter !== $expectedStatusFilter || $decoded->expiresAt <= $this->clock->now()) {
                throw new InvalidArgumentException;
            }

            return $decoded;
        } catch (Throwable $exception) {
            throw $this->invalid($exception);
        }
    }

    private function payload(ReportSubscriptionCursor $cursor): array
    {
        return ['expires_at' => $cursor->expiresAt->format(DATE_ATOM), 'last_id' => $cursor->lastId, 'last_next_run_at' => $cursor->lastNextRunAt?->format(DATE_ATOM), 'order' => $cursor->order, 'organization_id' => $cursor->organizationId, 'owner_id' => $cursor->ownerId, 'status_filter' => $cursor->statusFilter?->value, 'version' => $cursor->version];
    }

    private function hasValidPayloadShape(array $payload): bool
    {
        return array_keys($payload) === ['expires_at', 'last_id', 'last_next_run_at', 'order', 'organization_id', 'owner_id', 'status_filter', 'version']
            && is_string($payload['expires_at'])
            && is_string($payload['last_id'])
            && ($payload['last_next_run_at'] === null || is_string($payload['last_next_run_at']))
            && is_string($payload['order'])
            && is_int($payload['organization_id'])
            && is_int($payload['owner_id'])
            && ($payload['status_filter'] === null || is_string($payload['status_filter']))
            && is_int($payload['version']);
    }

    /** @return array{string,string} */
    private function segments(string $cursor): array
    {
        $segments = explode('.', $cursor);
        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new InvalidArgumentException;
        }

return [$segments[0], $segments[1]];
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException;
        } $at = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $at instanceof DateTimeImmutable || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $at->format(DATE_ATOM) !== $value || $at->getOffset() !== 0) {
            throw new InvalidArgumentException;
        }

return $at->setTimezone(new DateTimeZone('UTC'));
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException;
        } $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (! is_string($decoded) || self::b64($decoded) !== $value) {
            throw new InvalidArgumentException;
        }

return $decoded;
    }

    private function invalid(?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID, ['fields' => ['cursor']], $previous);
    }
}
