<?php

namespace App\Exceptions\Schedule;

use App\Http\Responses\AdminResponse;
use Exception;

class ScheduleNotFoundException extends Exception
{
    protected ?int $scheduleId = null;

    public function __construct(?int $scheduleId = null, ?string $message = null, int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? trans_message('schedule_management.schedule_not_found'), $code, $previous);
        $this->scheduleId = $scheduleId;
    }

    public function getScheduleId(): ?int
    {
        return $this->scheduleId;
    }

    public function render($request)
    {
        if ($request->expectsJson()) {
            return AdminResponse::error($this->getMessage(), $this->getCode(), null, [
                'schedule_id' => $this->scheduleId,
            ]);
        }

        return redirect()->back()->withErrors(['error' => $this->getMessage()]);
    }
}

