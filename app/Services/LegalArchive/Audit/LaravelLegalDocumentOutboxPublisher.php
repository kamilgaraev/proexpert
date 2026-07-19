<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentOutboxMessage;
use App\Events\LegalArchive\LegalDocumentOutboxPublished;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class LaravelLegalDocumentOutboxPublisher implements LegalDocumentOutboxPublisher
{
    public function __construct(private Dispatcher $events) {}

    public function publish(LegalDocumentOutboxMessage $message): void
    {
        $this->events->dispatch(new LegalDocumentOutboxPublished(
            messageId: (string) $message->id,
            organizationId: (int) $message->organization_id,
            aggregateType: (string) $message->aggregate_type,
            aggregateId: (string) $message->aggregate_id,
            event: (string) $message->event,
            payload: $message->payload ?? [],
        ));
    }
}
