<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportResourceLink
{
    public function __construct(
        public string $resourceType,
        public string $resourceId,
        public string $routeName,
        public array $params,
        public string $availability,
    ) {
        if (!self::isSafeIdentifier($resourceType) || !self::isSafeIdentifier($resourceId) || preg_match('/^[a-z][a-z0-9_.]{0,127}$/', $routeName) !== 1 || !in_array($availability, ['available', 'forbidden', 'missing'], true) || !self::hasSafeParams($params)) {
            throw new InvalidArgumentException('report_resource_link_invalid');
        }

        try {
            CanonicalJson::encode($params);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('report_resource_link_invalid', 0, $exception);
        }
    }

    private static function hasSafeParams(array $params): bool
    {
        foreach ($params as $key => $value) {
            if (!is_string($key) || !self::isSafeIdentifier($key)) {
                return false;
            }

            if (is_int($value) && $value > 0) {
                continue;
            }

            if (!is_string($value) || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || (!self::isSafeIdentifier($value) && !self::isUlid($value))) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }

    private static function isUlid(string $value): bool
    {
        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $value) === 1;
    }
}
