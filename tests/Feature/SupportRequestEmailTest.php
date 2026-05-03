<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SupportRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportRequestEmailTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_landing_support_request_sends_email_to_support_address(): void
    {
        $this->withoutMiddleware();

        config([
            'mail.support_address' => 'support@example.test',
        ]);

        Mail::fake();

        $user = new User([
            'name' => 'Иван Петров',
            'email' => 'ivan@example.test',
        ]);
        $user->id = 10;

        $response = $this->actingAs($user, 'api_landing')
            ->postJson('/api/v1/landing/support', [
                'subject' => 'Нужна помощь по доступу',
                'message' => 'Не получается открыть раздел документов.',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', trans_message('support.request_sent'));

        Mail::assertSent(SupportRequestMail::class, function (SupportRequestMail $mail): bool {
            $mail->assertHasSubject('Обращение в поддержку: Нужна помощь по доступу');

            return $mail->hasTo('support@example.test')
                && $mail->senderName === 'Иван Петров'
                && $mail->senderEmail === 'ivan@example.test'
                && $mail->messageText === 'Не получается открыть раздел документов.'
                && $mail->userId === 10;
        });
    }
}
