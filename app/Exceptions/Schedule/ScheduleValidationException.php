<?php

namespace App\Exceptions\Schedule;

use Exception;
use Illuminate\Support\Facades\Validator;

class ScheduleValidationException extends Exception
{
    protected array $errors = [];

    public function __construct(string $message = 'Ошибка валидации графика', array $errors = [], int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function setErrors(array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }

    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => $this->errors,
            ], $this->code);
        }

        return redirect()->back()->withErrors($this->errors)->withInput();
    }
}

