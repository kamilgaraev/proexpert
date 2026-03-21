<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Http\Resources\MessageResource;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class MessageResourceTest extends TestCase
{
    public function test_resource_exposes_structured_metadata(): void
    {
        $message = new Message([
            'id' => 11,
            'role' => 'assistant',
            'content' => 'Сводка готова',
            'metadata' => [
                'task_type' => 'summary',
                'answer' => 'Сводка готова',
                'access_limits' => [
                    ['code' => 'actions_locked', 'message' => 'Изменяющие действия отключены.'],
                ],
            ],
        ]);

        $data = (new MessageResource($message))->toArray(Request::create('/'));

        $this->assertSame('summary', $data['metadata']['task_type']);
        $this->assertSame('Сводка готова', $data['metadata']['answer']);
        $this->assertSame('actions_locked', $data['metadata']['access_limits'][0]['code']);
    }
}
