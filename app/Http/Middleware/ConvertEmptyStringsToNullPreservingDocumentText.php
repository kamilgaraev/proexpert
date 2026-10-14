<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;

final class ConvertEmptyStringsToNullPreservingDocumentText extends ConvertEmptyStringsToNull
{
    protected function transform($key, $value)
    {
        if (TrimStringsPreservingDocumentText::preservesTextKey($key)) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
