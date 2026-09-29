<?php

declare(strict_types=1);

namespace App\Support\AI;

use Closure;
use Yethee\Tiktoken\EncoderProvider;
use Yethee\Tiktoken\Exception\IOError;

final class TokenCounter
{
    private const MESSAGE_OVERHEAD = 5;

    private const TOOL_OVERHEAD = 12;

    private readonly Closure $encode;

    private static ?EncoderProvider $provider = null;

    public function __construct(?object $encoder = null)
    {
        if ($encoder === null) {
            if (self::$provider === null) {
                $cacheDir = getenv('TIKTOKEN_CACHE_DIR');
                self::ensureCacheDirectory($cacheDir !== false && $cacheDir !== ''
                    ? $cacheDir
                    : sys_get_temp_dir().DIRECTORY_SEPARATOR.'tiktoken');
                self::$provider = new EncoderProvider();
            }
            $encoder = self::$provider->get('o200k_base');
        }
        $this->encode = Closure::fromCallable([$encoder, 'encode']);
    }

    private static function ensureCacheDirectory(string $cacheDir): void
    {
        if (! is_dir($cacheDir) && ! @mkdir($cacheDir, 0750, true) && ! is_dir($cacheDir)) {
            throw new IOError(sprintf('Directory does not exist and cannot be created: %s', $cacheDir));
        }
    }

    public function text(string $text): int
    {
        return count(($this->encode)($text));
    }

    public function messages(array $messages): int
    {
        $tokens = 0;
        foreach ($messages as $message) {
            $tokens += self::MESSAGE_OVERHEAD + $this->value($message);
        }

        return $tokens + 3;
    }

    public function tools(array $tools): int
    {
        return $tools === [] ? 0 : self::TOOL_OVERHEAD + $this->value($tools);
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
