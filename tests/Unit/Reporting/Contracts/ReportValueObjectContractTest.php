<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportExportStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportValueObjectContractTest extends TestCase
{
    #[Test]
    public function idempotency_key_keeps_value_and_derives_lowercase_sha256_hash(): void
    {
        $key = new IdempotencyKey('12345678');

        self::assertSame('ef797c8118f02dfb649607dd5d3f8c7623048c9c063d532cc95c5ed7a898a64f', $key->hash);
    }

    #[Test]
    public function idempotency_key_rejects_values_outside_the_contract(): void
    {
        foreach (['1234567', str_repeat('a', 129), 'ключ-123'] as $value) {
            try {
                new IdempotencyKey($value);
                self::fail('Недопустимый ключ идемпотентности был принят.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_KEY_INVALID, $exception->errorCode);
            }
        }
    }

    #[Test]
    public function sha256_hash_accepts_only_lowercase_hexadecimal_hashes(): void
    {
        self::assertSame(str_repeat('a', 64), (new Sha256Hash(str_repeat('a', 64)))->value);

        foreach ([
            str_repeat('A', 64),
        ] as $value) {
            try {
                new Sha256Hash($value);
                self::fail('Недопустимый SHA-256 хеш был принят.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('sha256_invalid', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function canonical_json_sorts_associative_keys_recursively(): void
    {
        self::assertSame('{"a":2,"b":1,"nested":{"a":4,"b":3}}', CanonicalJson::encode([
            'b' => 1,
            'a' => 2,
            'nested' => ['b' => 3, 'a' => 4],
        ]));
    }

    #[Test]
    public function canonical_json_preserves_list_order_and_zero_fraction(): void
    {
        self::assertSame('[2,1,1.0]', CanonicalJson::encode([2, 1, 1.0]));
    }

    #[Test]
    public function canonical_json_rejects_unsupported_values(): void
    {
        $resource = fopen('php://memory', 'rb');

        try {
            foreach ([NAN, $resource, static fn (): null => null] as $value) {
                try {
                    CanonicalJson::encode(['value' => $value]);
                    self::fail('Недопустимое значение было закодировано.');
                } catch (InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function canonical_json_rejects_self_referential_arrays_without_recursing_unboundedly(): void
    {
        $value = [];
        $value['self'] = &$value;
        $previousLimit = ini_set('memory_limit', '32M');

        try {
            $this->expectException(InvalidArgumentException::class);
            CanonicalJson::encode($value);
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    #[Test]
    public function reporting_enums_expose_the_stable_scalar_values(): void
    {
        self::assertSame(['asc', 'desc'], array_column(ReportSortDirection::cases(), 'value'));
        self::assertSame(['queued', 'materializing', 'ready', 'failed', 'cancelled', 'expired'], array_column(ReportRunStatus::cases(), 'value'));
        self::assertSame(['queued', 'running', 'uploading', 'ready', 'failed', 'cancelled', 'expired'], array_column(ReportExportStatus::cases(), 'value'));
        self::assertSame(['fresh', 'stale', 'partial', 'unavailable'], array_column(ReportFreshnessStatus::cases(), 'value'));
        self::assertSame(['complete', 'partial', 'invalid'], array_column(ReportQualityStatus::cases(), 'value'));
        self::assertSame(['matched', 'mismatch', 'not_applicable'], array_column(ReportReconciliationStatus::cases(), 'value'));
        self::assertSame(['info', 'warning', 'critical'], array_column(ReportWarningSeverity::cases(), 'value'));
        self::assertSame(['view', 'run', 'export', 'download', 'manage', 'view_sensitive', 'view_audit', 'drill_down'], array_column(ReportOperation::cases(), 'value'));
        self::assertSame(['draft', 'candidate', 'published', 'blocked'], array_column(ReportPublicationReadiness::cases(), 'value'));
    }
}
