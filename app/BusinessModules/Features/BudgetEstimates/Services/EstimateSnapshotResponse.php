<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use RuntimeException;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EstimateSnapshotResponse
{
    public function __construct(private readonly EstimateStructureSnapshotStorage $storage) {}

    public function create(Request $request, array $meta, string $path): StreamedResponse
    {
        $prefix = '{"success":true,"message":null,"data":'.json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).',"tree":';
        $encodings = AcceptHeader::fromString($request->headers->get('Accept-Encoding', ''));
        $gzip = $encodings->get('gzip');
        $compress = $gzip !== null && $gzip->getQuality() > 0 && function_exists('deflate_init');
        $context = $compress ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
        if ($context === false) {
            throw new RuntimeException('estimate_response_compression_unavailable');
        }
        $stream = $this->storage->readStream($path);
        $response = new StreamedResponse(static function () use ($stream, $context, $prefix): void {
            $write = static function (string $chunk, bool $finish = false) use ($context): void {
                $output = $context === null ? $chunk : deflate_add($context, $chunk, $finish ? ZLIB_FINISH : ZLIB_NO_FLUSH);
                if ($output === false) {
                    throw new RuntimeException('estimate_response_compression_failed');
                }
                echo $output;
            };
            try {
                $write($prefix);
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        throw new RuntimeException('estimate_snapshot_read_failed');
                    }
                    $write($chunk);
                }
                $write('}', true);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
        $response->setVary('Accept-Encoding');
        if ($compress) {
            $response->headers->set('Content-Encoding', 'gzip');
        }

        return $response;
    }
}
