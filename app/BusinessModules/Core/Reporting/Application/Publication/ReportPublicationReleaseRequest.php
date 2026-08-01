<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseRequest
{
    private function __construct(public string $requestId) {}

    public static function fromArray(array $payload): self
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['request_id', 'schema_version']
            || $payload['schema_version'] !== '1.0.0'
            || ! is_string($payload['request_id'])
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $payload['request_id']) !== 1) {
            throw new InvalidArgumentException('report_publication_release_request_invalid');
        }

        return new self($payload['request_id']);
    }
}
