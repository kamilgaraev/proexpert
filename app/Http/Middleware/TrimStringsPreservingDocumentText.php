<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

final class TrimStringsPreservingDocumentText extends TrimStrings
{
    public static function preservesTextKey(string $key): bool
    {
        return preg_match('/^(?:content\.)?document\.content\.\d+(?:\.content\.\d+)*\.text$/D', $key) === 1
            || preg_match('/^content\.(?:columns\.\d+\.definition\.)*display\.presentation\.(?:prefix|suffix)$/D', $key) === 1;
    }

    protected function transform($key, $value)
    {
        if (self::preservesTextKey($key)) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
