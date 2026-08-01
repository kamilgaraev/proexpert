<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;
use JsonException;

final class ReportPublicationReleaseRequestFileLoader
{
    public function load(string $requestFile, string $trustedDirectory): ReportPublicationReleaseRequest
    {
        $trustedDirectory = realpath($trustedDirectory);
        if ($trustedDirectory === false || is_link($requestFile)) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }
        $requestFile = realpath($requestFile);
        if ($requestFile === false
            || ! str_starts_with($requestFile, $trustedDirectory.DIRECTORY_SEPARATOR)
            || pathinfo($requestFile, PATHINFO_EXTENSION) !== 'json') {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }
        $bytes = file_get_contents($requestFile);
        if ($bytes === false) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }
        try {
            $payload = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }
        if (! is_array($payload)) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }
        $request = ReportPublicationReleaseRequest::fromArray($payload);
        if (pathinfo($requestFile, PATHINFO_FILENAME) !== $request->requestId) {
            throw new InvalidArgumentException('report_publication_release_input_invalid');
        }

        return $request;
    }
}
