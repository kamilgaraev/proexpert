<?php

declare(strict_types=1);

namespace App\Notifications\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Notifications\Notification;

final class LegalDocumentDeadlineNotification extends Notification
{
    public function __construct(
        private readonly LegalArchiveDocument $document,
        private readonly string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'legal_document_'.$this->kind,
            'document_id' => (int) $this->document->id,
            'title' => $this->document->title,
            'message' => $this->kind === 'obligation_overdue'
                ? 'Просрочено обязательство по юридическому документу'
                : 'Приближается контрольный срок юридического документа',
            'targetRoute' => '/legal-archive/documents/'.(int) $this->document->id,
        ];
    }
}
