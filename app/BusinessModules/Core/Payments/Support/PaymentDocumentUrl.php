<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Support;

final class PaymentDocumentUrl
{
    public static function forAdmin(int $documentId): string
    {
        return rtrim((string) config('app.admin_frontend_url'), '/').'/payments?document_id='.$documentId;
    }
}
