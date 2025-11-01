<?php

namespace App\Exceptions\Schedule;

use Exception;

class ResourceConflictException extends Exception
{
    protected array $conflictDetails = [];

    public function __construct(string $message = 'Обнаружены конфликты ресурсов', array $conflictDetails = [], int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->conflictDetails = $conflictDetails;
    }

    public function getConflictDetails(): array
    {
        return $this->conflictDetails;
    }

    public function setConflictDetails(array $conflictDetails): self
    {
        $this->conflictDetails = $conflictDetails;
        return $this;
    }

    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'conflicts' => $this->conflictDetails,
            ], $this->code);
        }

        return redirect()->back()->with('error', $this->getMessage());
    }
}

