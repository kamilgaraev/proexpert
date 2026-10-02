<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Support\AI\TokenCounter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Yethee\Tiktoken\EncoderProvider;
use Yethee\Tiktoken\Vocab\Vocab;
use Yethee\Tiktoken\Vocab\VocabLoader;

final class TokenCounterLazyInitializationTest extends TestCase
{
    public function test_default_encoder_is_initialized_once_on_first_non_empty_text(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'token-counter-lazy-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $loads = (object) ['count' => 0];
        $provider = new EncoderProvider;
        $provider->setVocabLoader(new class($loads) implements VocabLoader
        {
            public function __construct(private object $loads) {}

            public function load(string $uri, ?string $checksum = null): Vocab
            {
                $this->loads->count++;
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, base64_encode('hello').' 0'."\n");
                rewind($stream);

                try {
                    return Vocab::fromStream($stream);
                } finally {
                    fclose($stream);
                }
            }

            public function loadFile(string $uri, ?string $checksum = null): string
            {
                throw new RuntimeException('unexpected_vocab_file_load');
            }
        });
        $providerProperty = new ReflectionProperty(TokenCounter::class, 'provider');
        $cacheDirProperty = new ReflectionProperty(TokenCounter::class, 'cacheDir');
        $providerIsDefaultProperty = new ReflectionProperty(TokenCounter::class, 'providerIsDefault');
        $encoderProperty = new ReflectionProperty(TokenCounter::class, 'encode');
        $previousProvider = $providerProperty->getValue();
        $previousCacheDir = $cacheDirProperty->isInitialized() ? $cacheDirProperty->getValue() : '';
        $previousProviderIsDefault = $providerIsDefaultProperty->getValue();

        try {
            $providerProperty->setValue(null, $provider);
            $cacheDirProperty->setValue(null, $directory);
            $providerIsDefaultProperty->setValue(null, false);

            $counter = new TokenCounter;

            self::assertSame(0, $loads->count);
            self::assertSame(0, $counter->text(''));
            self::assertSame(0, $loads->count);

            self::assertSame(1, $counter->text('hello'));
            self::assertSame(1, $loads->count);
            $encode = $encoderProperty->getValue($counter);
            self::assertInstanceOf(\Closure::class, $encode);

            self::assertSame(1, $counter->text('hello'));
            self::assertSame(1, $loads->count);
            self::assertSame($encode, $encoderProperty->getValue($counter));
        } finally {
            $providerProperty->setValue(null, $previousProvider);
            $cacheDirProperty->setValue(null, $previousCacheDir);
            $providerIsDefaultProperty->setValue(null, $previousProviderIsDefault);
            $lockFile = $directory.DIRECTORY_SEPARATOR.'.o200k_base.lock';
            if (is_file($lockFile)) {
                unlink($lockFile);
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_cold_default_cache_repairs_tampering_from_bundled_vocab_without_https(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'token-counter-bundled-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $uri = 'https://openaipublic.blob.core.windows.net/encodings/o200k_base.tiktoken';
        $cacheFile = $directory.DIRECTORY_SEPARATOR.sha1($uri);
        file_put_contents($cacheFile, 'tampered');
        $providerProperty = new ReflectionProperty(TokenCounter::class, 'provider');
        $cacheDirProperty = new ReflectionProperty(TokenCounter::class, 'cacheDir');
        $providerIsDefaultProperty = new ReflectionProperty(TokenCounter::class, 'providerIsDefault');
        $previousProvider = $providerProperty->getValue();
        $previousCacheDir = $cacheDirProperty->isInitialized() ? $cacheDirProperty->getValue() : '';
        $previousProviderIsDefault = $providerIsDefaultProperty->getValue();
        $previousCacheSetting = getenv('TIKTOKEN_CACHE_DIR');
        $httpsWasUnregistered = false;
        $httpsWasRegistered = false;

        try {
            putenv('TIKTOKEN_CACHE_DIR='.$directory);
            $providerProperty->setValue(null, null);
            $providerIsDefaultProperty->setValue(null, false);
            $httpsWasUnregistered = stream_wrapper_unregister('https');
            self::assertTrue($httpsWasUnregistered);
            $httpsWasRegistered = stream_wrapper_register('https', TokenCounterBlockedHttpsStream::class);
            self::assertTrue($httpsWasRegistered);

            $counter = new TokenCounter;
            self::assertSame(1, $counter->text('hello'));
            self::assertSame(0, TokenCounterBlockedHttpsStream::$opens);
            self::assertSame('446a9538cb6c348e3516120d7c08b09f57c36495e2acfffe59a5bf8b0cfb1a2d', hash_file('sha256', $cacheFile));

            $mtime = time() - 3600;
            touch($cacheFile, $mtime);
            self::assertSame(1, (new TokenCounter)->text('hello'));
            self::assertSame($mtime, filemtime($cacheFile));
            self::assertSame(0, TokenCounterBlockedHttpsStream::$opens);
        } finally {
            if ($httpsWasRegistered) {
                stream_wrapper_unregister('https');
            }
            if ($httpsWasUnregistered) {
                stream_wrapper_restore('https');
            }
            putenv($previousCacheSetting === false ? 'TIKTOKEN_CACHE_DIR' : 'TIKTOKEN_CACHE_DIR='.$previousCacheSetting);
            $providerProperty->setValue(null, $previousProvider);
            $cacheDirProperty->setValue(null, $previousCacheDir);
            $providerIsDefaultProperty->setValue(null, $previousProviderIsDefault);
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            $lockFile = $directory.DIRECTORY_SEPARATOR.'.o200k_base.lock';
            if (is_file($lockFile)) {
                unlink($lockFile);
            }
            rmdir($directory);
        }
    }

    public function test_concurrent_cold_counters_seed_a_shared_cache_under_the_lock(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'token-counter-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $workerFile = $directory.DIRECTORY_SEPARATOR.'worker.php';
        $autoloadFile = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
        $worker = sprintf(<<<'PHP'
<?php
require %s;
final class TokenCounterWorkerBlockedHttpsStream
{
    public $context;
    public static int $opens = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { self::$opens++; return false; }
    public function stream_read(int $count): string|false { return false; }
    public function stream_eof(): bool { return true; }
    public function stream_stat(): array|false { return false; }
    public function stream_close(): void {}
}
stream_wrapper_unregister('https');
stream_wrapper_register('https', TokenCounterWorkerBlockedHttpsStream::class);
$count = (new App\Support\AI\TokenCounter)->text('hello');
if ($count !== 1 || TokenCounterWorkerBlockedHttpsStream::$opens !== 0) { exit(2); }
echo $count;
PHP, var_export($autoloadFile, true));
        file_put_contents($workerFile, $worker);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processes = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, $workerFile], $descriptors, $pipes, dirname(__DIR__, 3), ['TIKTOKEN_CACHE_DIR' => $directory]);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }

            foreach ($processes as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
                self::assertSame('1', trim($output));
            }

            $uri = 'https://openaipublic.blob.core.windows.net/encodings/o200k_base.tiktoken';
            $cacheFile = $directory.DIRECTORY_SEPARATOR.sha1($uri);
            self::assertSame('446a9538cb6c348e3516120d7c08b09f57c36495e2acfffe59a5bf8b0cfb1a2d', hash_file('sha256', $cacheFile));
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            $lockFile = $directory.DIRECTORY_SEPARATOR.'.o200k_base.lock';
            if (is_file($lockFile)) {
                unlink($lockFile);
            }
            rmdir($directory);
        }
    }
}

final class TokenCounterBlockedHttpsStream
{
    public $context;

    public static int $opens = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opens++;

        return false;
    }

    public function stream_read(int $count): string|false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_stat(): array|false
    {
        return false;
    }

    public function stream_close(): void {}
}
