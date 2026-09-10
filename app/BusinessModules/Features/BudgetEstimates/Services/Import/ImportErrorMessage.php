<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Lang;
use Throwable;

final class ImportErrorMessage
{
    public static function fromException(Throwable $exception): string
    {
        return $exception instanceof QueryException
            ? trans_message('estimate.import_save_failed')
            : (self::fromStored($exception->getMessage()) ?? trans_message('estimate.import_failed'));
    }

    public static function fromStored(mixed $message): ?string
    {
        if (! is_string($message) || trim($message) === '') {
            return null;
        }

        $message = trim($message);
        if (preg_match('/^estimate\.[a-z0-9_]+$/', $message) === 1 && Lang::has($message)) {
            return trans_message($message);
        }

        $translations = Lang::get('estimate');
        if (is_array($translations) && in_array($message, $translations, true)) {
            return $message;
        }

        return trans_message(str_contains($message, 'SQLSTATE[')
            ? 'estimate.import_save_failed'
            : 'estimate.import_failed');
    }
}
