<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use Aws\S3\S3Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\Filesystem;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/** Keeps the actual SDK, FileService and pipeline artifact store; only HTTP is replaced. */
final class InMemoryS3HttpStorage
{
    private const BUCKET = 'estimator-test';

    private array $objects = [];

    public function install(?\Closure $intercept = null): void
    {
        $client = new S3Client(['version' => '2006-03-01', 'region' => 'us-east-1',
            'endpoint' => 'https://s3.fixture.invalid', 'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test-only', 'secret' => 'test-only'],
            'http_handler' => function (RequestInterface $request) use ($intercept): PromiseInterface {
                $response = $intercept?->__invoke($request);

                return $response instanceof Response ? $this->response($request, $response) : $this->handle($request);
            }]);
        $adapter = new FlysystemS3Adapter($client, self::BUCKET);
        Storage::set('s3', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, ['bucket' => self::BUCKET], $client));
    }

    private function handle(RequestInterface $request): PromiseInterface
    {
        $path = rawurldecode($request->getUri()->getPath());
        $prefix = '/'.self::BUCKET.'/';
        if (! str_starts_with($path, $prefix)) {
            throw new RuntimeException('Unexpected test S3 bucket.');
        }
        $key = substr($path, strlen($prefix));
        parse_str($request->getUri()->getQuery(), $query);
        $method = $request->getMethod();
        if ($method === 'GET' && array_key_exists('acl', $query)) {
            return $this->response($request, isset($this->objects[$key])
                ? new Response(200, ['Content-Type' => 'application/xml'], '<AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Owner><ID>fixture-owner</ID></Owner><AccessControlList><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser"><ID>fixture-owner</ID></Grantee><Permission>FULL_CONTROL</Permission></Grant></AccessControlList></AccessControlPolicy>')
                : new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
        }
        if ($method === 'PUT' && array_key_exists('tagging', $query)) {
            return $this->response($request, new Response(isset($this->objects[$key]) ? 200 : 404));
        }
        if ($method === 'PUT') {
            if ($request->getHeaderLine('If-None-Match') === '*' && isset($this->objects[$key])) {
                return $this->response($request, new Response(412, ['Content-Type' => 'application/xml'],
                    '<Error><Code>PreconditionFailed</Code><Message>Object exists</Message></Error>'));
            }
            $copySource = $request->getHeaderLine('x-amz-copy-source');
            if ($copySource !== '') {
                $sourceKey = preg_replace('~^/?'.self::BUCKET.'/~', '', rawurldecode($copySource));
                $source = $this->objects[$sourceKey] ?? null;
                if ($source === null) {
                    return $this->response($request, new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
                }
                $body = $source['body'];
            } else {
                $body = (string) $request->getBody();
            }
            $headers = ['ETag' => '"'.hash('sha256', $body).'"', 'Content-Length' => (string) strlen($body),
                'Content-Type' => $request->getHeaderLine('Content-Type') ?: 'application/octet-stream',
                'Last-Modified' => 'Mon, 12 Oct 2026 00:00:00 GMT', 'x-amz-version-id' => hash('sha256', $key.'|'.$body)];
            $this->objects[$key] = ['body' => $body, 'headers' => $headers];

            if ($copySource !== '') {
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/xml', 'x-amz-version-id' => $headers['x-amz-version-id']],
                    '<CopyObjectResult><LastModified>2026-10-12T00:00:00Z</LastModified><ETag>'.htmlspecialchars($headers['ETag'], ENT_XML1).'</ETag></CopyObjectResult>'));
            }

            return Create::promiseFor(new Response(200, ['ETag' => $headers['ETag'], 'x-amz-version-id' => $headers['x-amz-version-id']]));
        }
        if (in_array($method, ['HEAD', 'GET'], true)) {
            $object = $this->objects[$key] ?? null;
            if ($object === null) {
                return $this->response($request, new Response(404, ['Content-Type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
            }
            if (($query['versionId'] ?? $object['headers']['x-amz-version-id']) !== $object['headers']['x-amz-version-id']
                || ($request->hasHeader('If-Match') && $request->getHeaderLine('If-Match') !== $object['headers']['ETag'])) {
                return $this->response($request, new Response(412, ['Content-Type' => 'application/xml'], '<Error><Code>PreconditionFailed</Code></Error>'));
            }

            return Create::promiseFor(new Response(200, $object['headers'], $method === 'GET' ? $object['body'] : ''));
        }
        if ($method === 'DELETE') {
            unset($this->objects[$key]);

            return Create::promiseFor(new Response(204));
        }
        throw new RuntimeException('Unexpected test S3 operation.');
    }

    private function response(RequestInterface $request, Response $response): PromiseInterface
    {
        // AWS expects HTTP failures as rejected promises, just like its Guzzle transport.
        return $response->getStatusCode() >= 400
            ? Create::rejectionFor(['response' => $response, 'exception' => new RequestException('Fixture HTTP failure.', $request, $response)])
            : Create::promiseFor($response);
    }
}
