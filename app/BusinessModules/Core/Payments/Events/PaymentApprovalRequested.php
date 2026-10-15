<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Events;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class PaymentApprovalRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentDocument $document,
        public readonly int $level,
    ) {}
}
