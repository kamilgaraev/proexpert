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
            if (is_string($key) && preg_match('/(?:model|class|table|url)/i', $key) === 1) {
                return false;
            }

            if (is_string($value) && preg_match('/(?:^[a-z][a-z0-9+.-]*:\/\/|^\/)/i', $value) === 1) {
                return false;
            }

            if (is_array($value) && !self::hasSafeParams($value)) {
                return false;
            }
        }

        return true;
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1;
    }
}
