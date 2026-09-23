<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Requests;

use App\Models\User;

final class MobileSubmitPaymentDocumentRequest extends SubmitPaymentDocumentRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }
}
