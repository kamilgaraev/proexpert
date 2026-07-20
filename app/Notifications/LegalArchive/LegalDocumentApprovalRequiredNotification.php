<?php

declare(strict_types=1);

namespace App\Notifications\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use Illuminate\Notifications\Notification;

final class LegalDocumentApprovalRequiredNotification extends Notification
{
    public function __construct(private readonly LegalArchiveDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'legal_document_approval_required',
            'document_id' => (int) $this->document->id,
            'title' => $this->document->title,
            'message' => 'Требуется согласование юридического документа',
            'targetRoute' => '/legal-archive/documents/'.(int) $this->document->id,
        ];
    }
}
