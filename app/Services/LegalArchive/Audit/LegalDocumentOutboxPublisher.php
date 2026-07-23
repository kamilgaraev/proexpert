<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;

interface LegalDocumentOutboxPublisher
{
    public function publish(LegalDocumentOutboxMessage $message): void;
}
