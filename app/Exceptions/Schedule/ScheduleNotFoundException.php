<?php

namespace App\Exceptions\Schedule;

use Exception;

class ScheduleNotFoundException extends Exception
{
    protected ?int $scheduleId = null;

    public function __construct(?int $scheduleId = null, string $message = 'График не найден', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->scheduleId = $scheduleId;
    }

    public function getScheduleId(): ?int
    {
        return $this->scheduleId;
    }

    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'schedule_id' => $this->scheduleId,
            ], $this->code);
        }

        return redirect()->back()->withErrors(['error' => $this->getMessage()]);
    }
}

