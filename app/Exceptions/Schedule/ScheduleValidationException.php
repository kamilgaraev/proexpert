<?php

namespace App\Exceptions\Schedule;

use App\Http\Responses\AdminResponse;
use Exception;

class ScheduleValidationException extends Exception
{
    protected array $errors = [];

    public function __construct(?string $message = null, array $errors = [], int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? trans_message('schedule_management.validation_error'), $code, $previous);
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
            return AdminResponse::error($this->getMessage(), $this->getCode(), $this->errors);
        }

        return redirect()->back()->withErrors($this->errors)->withInput();
    }
}

