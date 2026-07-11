<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Providers;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionResponseBodyReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use Illuminate\Http\Client\Response;

final readonly class BoundedVisionResponseBodyReader implements VisionResponseBodyReader
{
    public function read(Response $response, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new VisionContractException('vision_response_limit_invalid');
        }
        $contentLength = $response->header('Content-Length');
        if ($contentLength !== '' && preg_match('/^(?:0|[1-9][0-9]{0,15})$/', $contentLength) !== 1) {
            throw new VisionContractException('vision_content_length_invalid');
        }
        if ($contentLength !== '' && (int) $contentLength > $maxBytes) {
            throw new VisionContractException('vision_response_too_large');
        }
        $resource = $response->resource();
        $body = '';
        try {
            while (! feof($resource)) {
                $remaining = $maxBytes + 1 - strlen($body);
                if ($remaining <= 0) {
                    throw new VisionContractException('vision_response_too_large');
                }
                $chunk = fread($resource, min(65_536, $remaining));
                if ($chunk === false || ($chunk === '' && ! feof($resource))) {
                    throw new VisionContractException('vision_response_read_failed');
                }
                $body .= $chunk;
            }
        } finally {
            fclose($resource);
        }
        if ($body === '') {
            throw new VisionContractException('vision_response_empty');
        }

        return $body;
    }
}
