<?php

namespace App\Exceptions\Schedule;

use App\Http\Responses\AdminResponse;
use Exception;

class CircularDependencyException extends Exception
{
    protected array $cycleTasks = [];

    public function __construct(?string $message = null, array $cycleTasks = [], int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? trans_message('schedule_management.validation_error'), $code, $previous);
        $this->cycleTasks = $cycleTasks;
    }

    public function getCycleTasks(): array
    {
        return $this->cycleTasks;
    }

    public function setCycleTasks(array $cycleTasks): self
    {
        $this->cycleTasks = $cycleTasks;
        return $this;
    }

    public function render($request)
    {
        if ($request->expectsJson()) {
            return AdminResponse::error($this->getMessage(), $this->getCode(), null, [
                'cycle_tasks' => $this->cycleTasks,
            ]);
        }

        return redirect()->back()->withErrors(['error' => $this->getMessage()]);
    }
}

