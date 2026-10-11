<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FileServiceEstimatorStreamTest extends TestCase
{
    public function test_estimator_upload_passes_a_private_stream_and_closes_it_after_success(): void
    {
        $this->upload(false);
    }

    public function test_tag_failure_compensates_the_new_object_and_closes_the_stream(): void
    {
        $this->upload(true);
    }

    private function upload(bool $failTag): void
    {
        $previousFacade = Facade::getFacadeApplication();
        $temporary = tmpfile();
        self::assertIsResource($temporary);
        fwrite($temporary, '%PDF-1.7 uploaded content');
        $path = stream_get_meta_data($temporary)['uri'];
        $stream = null;
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->allows('info');
        $logger->allows('error');
        $logger->allows('warning');
        Facade::setFacadeApplication(new Container);
        Log::swap($logger);
        $guard = Mockery::mock(\Illuminate\Contracts\Auth\Guard::class);
        $guard->allows('id')->andReturn(4);
        Auth::swap($guard);
        try {
            $logging = Mockery::mock(LoggingService::class);
            $logging->allows('technical');
            $logging->allows('business');
            $disk = Mockery::mock(FilesystemAdapter::class);
            $disk->allows('getConfig')->andReturn(['driver' => 's3', 'bucket' => 'most']);
            $disk->expects('put')->once()->withArgs(function (string $objectPath, mixed $content, mixed $visibility) use (&$stream): bool {
                self::assertStringStartsWith('org-7/estimate-generation/sessions/41/documents/', $objectPath);
                self::assertIsResource($content);
                self::assertSame('private', $visibility);
                $stream = $content;
                self::assertSame('%PDF-1.7 uploaded content', stream_get_contents($content));

                return true;
            })->andReturn(true);
            $client = Mockery::mock(S3ClientInterface::class);
            if ($failTag) {
                $client->expects('putObjectTagging')->once()->andThrow(new \RuntimeException('tag transport failure'));
                $client->expects('deleteObject')->once()->withArgs(static fn (array $arguments): bool => $arguments['Bucket'] === 'most' && str_starts_with($arguments['Key'], 'org-7/estimate-generation/sessions/41/documents/'))
                    ->andReturn(new Result);
            } else {
                $client->expects('putObjectTagging')->once()->andReturn(new Result);
                $client->shouldNotReceive('deleteObject');
            }
            $files = Mockery::mock(FileService::class, [$logging])->makePartial()->shouldAllowMockingProtectedMethods();
            $files->allows('disk')->andReturn($disk);
            $files->allows('s3Client')->andReturn($client);
            $organization = new Organization;
            $organization->forceFill(['id' => 7]);
            $result = $files->upload(new UploadedFile($path, 'plan.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
                'estimate-generation/sessions/41/documents', null, 'private', $organization, false, true);
            if ($failTag) {
                self::assertFalse($result);
            } else {
                self::assertIsString($result);
                self::assertStringStartsWith('org-7/estimate-generation/sessions/41/documents/', $result);
            }
            self::assertNotNull($stream);
            self::assertFalse(is_resource($stream));
        } finally {
            fclose($temporary);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
            Mockery::close();
        }
    }
}
