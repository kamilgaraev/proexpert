<?php

declare(strict_types=1);

namespace App\Support\AI;

use Closure;
use Yethee\Tiktoken\EncoderProvider;
use Yethee\Tiktoken\Exception\IOError;

final class TokenCounter
{
    private const VOCABULARY_URL = 'https://openaipublic.blob.core.windows.net/encodings/o200k_base.tiktoken';

    private const VOCABULARY_SHA256 = '446a9538cb6c348e3516120d7c08b09f57c36495e2acfffe59a5bf8b0cfb1a2d';

    private const MESSAGE_OVERHEAD = 5;

    private const TOOL_OVERHEAD = 12;

    private ?Closure $encode = null;

    private static ?EncoderProvider $provider = null;

    private static string $cacheDir;

    private static bool $providerIsDefault = false;

    public function __construct(?object $encoder = null)
    {
        if ($encoder !== null) {
            $this->encode = Closure::fromCallable([$encoder, 'encode']);
        }
    }

    private static function defaultEncoder(): Closure
    {
        if (self::$provider === null) {
            $cacheDir = getenv('TIKTOKEN_CACHE_DIR');
            $cacheDir = $cacheDir !== false && $cacheDir !== ''
                ? $cacheDir
                : sys_get_temp_dir().DIRECTORY_SEPARATOR.'tiktoken';
            self::ensureCacheDirectory($cacheDir);
            self::$provider = new EncoderProvider;
            self::$cacheDir = $cacheDir;
            self::$providerIsDefault = true;
        }

        $provider = self::$provider;
        $cacheDir = self::$cacheDir;
        $useBundledVocabulary = self::$providerIsDefault;
        $encoder = self::withCacheLock($cacheDir, static function () use ($provider, $cacheDir, $useBundledVocabulary): object {
            if ($useBundledVocabulary) {
                self::seedBundledVocabulary($cacheDir);
            }

            return $provider->get('o200k_base');
        });

        return Closure::fromCallable([$encoder, 'encode']);
    }

    private static function seedBundledVocabulary(string $cacheDir): void
    {
        $cacheFile = $cacheDir.DIRECTORY_SEPARATOR.sha1(self::VOCABULARY_URL);
        if (self::hasValidVocabularyHash($cacheFile)) {
            return;
        }

        $app = function_exists('app') ? app() : null;
        $bundledFile = $app !== null && method_exists($app, 'basePath')
            ? $app->basePath().DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'ai'.DIRECTORY_SEPARATOR.'tokenizer'.DIRECTORY_SEPARATOR.'o200k_base.tiktoken'
            : dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'ai'.DIRECTORY_SEPARATOR.'tokenizer'.DIRECTORY_SEPARATOR.'o200k_base.tiktoken';
        if (! self::hasValidVocabularyHash($bundledFile)) {
            return;
        }

        $temporaryFile = @tempnam($cacheDir, '.o200k_base.');
        if (! is_string($temporaryFile)) {
            return;
        }

        try {
            if (! @copy($bundledFile, $temporaryFile) || ! self::hasValidVocabularyHash($temporaryFile)) {
                return;
            }
            if (is_file($cacheFile) && ! @unlink($cacheFile)) {
                return;
            }

            @rename($temporaryFile, $cacheFile);
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    private static function hasValidVocabularyHash(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $hash = @hash_file('sha256', $path);

        return is_string($hash) && hash_equals(self::VOCABULARY_SHA256, $hash);
    }

    private static function ensureCacheDirectory(string $cacheDir): void
    {
        if (! is_dir($cacheDir) && ! @mkdir($cacheDir, 0750, true) && ! is_dir($cacheDir)) {
            throw new IOError(sprintf('Directory does not exist and cannot be created: %s', $cacheDir));
        }
    }

    private static function withCacheLock(string $cacheDir, Closure $load): mixed
    {
        $lockFile = $cacheDir.DIRECTORY_SEPARATOR.'.o200k_base.lock';
        $lock = @fopen($lockFile, 'c');

        if ($lock === false) {
            throw new IOError(sprintf('Could not open file for write: %s', $lockFile));
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new IOError(sprintf('Could not lock file: %s', $lockFile));
            }

            try {
                return $load();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    public function text(string $text): int
    {
        if ($text === '' && $this->encode === null) {
            return 0;
        }

        $this->encode ??= self::defaultEncoder();

        return count(($this->encode)($text));
    }

    public function messages(array $messages): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            if (is_array($message['content'] ?? null)) {
                $content = $message['content'];
                unset($message['content']);
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'image_url') {
                        $tokens += 4096;
                    } else {
                        $tokens += $this->value($part);
                    }
                }
            }
            $tokens += self::MESSAGE_OVERHEAD + $this->value($message);
        }

        return $tokens + 3;
    }

    public function tools(array $tools): int
    {
        return $tools === [] ? 0 : self::TOOL_OVERHEAD + $this->value($tools);
    }

    public function imageTokens(array $messages): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $part) {
                if (($part['type'] ?? null) === 'image_url') { $tokens += 4096; }
            }
        }
        return $tokens;
    }

    public function value(mixed $value): int
    {
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_scalar($value) || $value === null) {
            return $this->text((string) $value);
        }
        return $this->text(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
