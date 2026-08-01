<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportProvenance
{
    public function __construct(
        public string $sourceOfTruth,
        public array $sourceRefs,
        public Sha256Hash $sourceHash,
        public ?string $externalConfirmationRole,
    ) {
        if (!self::isSafeIdentifier($sourceOfTruth) || ($externalConfirmationRole !== null && !self::isSafeIdentifier($externalConfirmationRole)) || !self::isTypedSourceRefs($sourceRefs)) {
            throw new InvalidArgumentException('report_provenance_invalid');
        }

        self::assertSafeValue([$sourceOfTruth, $sourceRefs, $externalConfirmationRole]);
    }

    private static function isTypedSourceRefs(array $sourceRefs): bool
    {
        if (!array_is_list($sourceRefs) || $sourceRefs === []) {
            return false;
        }

        foreach ($sourceRefs as $sourceRef) {
            if (!$sourceRef instanceof ReportSourceRef) {
                return false;
            }
        }

        return true;
    }

    private static function assertSafeValue(mixed $value, ?string $key = null): void
    {
        if ($key !== null && preg_match('/(?:email|e_mail|private[_-]?url|url|sql|secret|password|token|query|filter)/i', $key) === 1) {
            throw new InvalidArgumentException('report_provenance_invalid');
        }

        if (is_string($value) && (preg_match('/(?:@|https?:\/\/|\b(?:email|sql|select|insert|update|delete|drop|alter|union)\b|secret|password|bearer\s|akia)/i', $value) === 1)) {
            throw new InvalidArgumentException('report_provenance_invalid');
        }

        if (is_array($value)) {
            foreach ($value as $nestedKey => $nestedValue) {
                self::assertSafeValue($nestedValue, is_string($nestedKey) ? $nestedKey : null);
            }
        }

        if ($value instanceof ReportSourceRef) {
            self::assertSafeValue(get_object_vars($value));
        }
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }
}
