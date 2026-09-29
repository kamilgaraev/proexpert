<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;

final class ChatAttachment extends Model
{
    protected $table = 'ai_chat_attachments';

    protected $guarded = ['id'];

    protected $hidden = ['storage_path', 'checksum', 'request_id', 'organization_id', 'user_id'];

    protected $casts = ['organization_id' => 'integer', 'user_id' => 'integer', 'conversation_id' => 'integer', 'message_id' => 'integer', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    public function publicMetadata(): array
    {
        return ['id' => $this->public_id, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size, 'width' => $this->width, 'height' => $this->height];
    }
}
