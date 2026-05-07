<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;
use TypeError;

class LivewirePayloadExceptionClassifier
{
    public function isMalformedClientUpdate(Throwable $exception, ?Request $request = null): bool
    {
        if (!$this->isLivewireUpdateRequest($request)) {
            return false;
        }

        $message = $exception->getMessage();

        if (is_a($exception, 'Livewire\\Features\\SupportLockedProperties\\CannotUpdateLockedPropertyException')) {
            return true;
        }

        if (str_contains($message, 'Cannot update locked property')) {
            return true;
        }

        if (is_a($exception, 'Filament\\Actions\\Exceptions\\ActionNotResolvableException')) {
            return true;
        }

        if (!$exception instanceof TypeError) {
            return false;
        }

        return str_contains($message, 'Filament\\Notifications')
            && (
                str_contains($message, 'must be of type array')
                || str_contains($message, 'of type bool')
            );
    }

    private function isLivewireUpdateRequest(?Request $request): bool
    {
        if (!$request instanceof Request || !$request->isMethod('POST')) {
            return false;
        }

        $path = trim($request->path(), '/');

        return str_contains($path, 'livewire')
            && str_ends_with($path, '/update');
    }
}
