<?php

namespace App\Exceptions\Schedule;

use App\Http\Responses\AdminResponse;
use Exception;

class ResourceConflictException extends Exception
{
    protected array $conflictDetails = [];

    public function __construct(?string $message = null, array $conflictDetails = [], int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? trans_message('schedule_management.resource_conflicts_found'), $code, $previous);
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
            return AdminResponse::error($this->getMessage(), $this->getCode(), null, [
                'conflicts' => $this->conflictDetails,
            ]);
        }

        return redirect()->back()->with('error', $this->getMessage());
    }
}

