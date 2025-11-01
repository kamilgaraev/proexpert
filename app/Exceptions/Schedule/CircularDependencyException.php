<?php

namespace App\Exceptions\Schedule;

use Exception;
use Illuminate\Support\Collection;

class CircularDependencyException extends Exception
{
    protected array $cycleTasks = [];

    public function __construct(string $message = 'Обнаружены циклические зависимости в графике', array $cycleTasks = [], int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
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
            return response()->json([
                'message' => $this->getMessage(),
                'cycle_tasks' => $this->cycleTasks,
            ], $this->code);
        }

        return redirect()->back()->withErrors(['error' => $this->getMessage()]);
    }
}

