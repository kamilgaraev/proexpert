<?php

declare(strict_types=1);

namespace App\Http\Requests\ConstructionJournal;

final class StoreEntryAttachmentRequest extends ConstructionJournalFormRequest
{
    public function rules(): array
    {
        return ['file' => ['bail', 'required', 'file', 'max:10240', 'image', 'mimetypes:image/png,image/jpeg,image/webp']];
    }
}
