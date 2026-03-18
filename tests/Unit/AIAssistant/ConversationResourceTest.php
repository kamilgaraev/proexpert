<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ConversationResourceTest extends TestCase
{
    public function test_resource_handles_missing_last_message_relation(): void
    {
        $conversation = new Conversation([
            'id' => 62,
            'title' => 'Тестовый диалог',
            'user_id' => 45,
        ]);

        $data = (new ConversationResource($conversation))->toArray(Request::create('/'));

        $this->assertNull($data['last_message_preview']);
        $this->assertNull($data['last_message_at']);
    }
}
