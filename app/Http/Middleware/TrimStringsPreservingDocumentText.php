<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

final class TrimStringsPreservingDocumentText extends TrimStrings
{
    protected function transform($key, $value)
    {
        if (preg_match('/^(?:content\.)?document\.content\.\d+(?:\.content\.\d+)*\.text$/D', $key) === 1
            || preg_match('/^content\.(?:columns\.\d+\.definition\.)*display\.presentation\.(?:prefix|suffix)$/D', $key) === 1) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
