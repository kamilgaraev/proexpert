<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\SupportRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DatabaseLessTestCase;

class SupportRequestEmailTest extends DatabaseLessTestCase
{
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

    public function test_default_support_recipient_is_the_request_inbox_instead_of_the_sender(): void
    {
        $this->withoutMiddleware();
        config(['mail.from.address' => 'noreply@example.test']);
        Mail::fake();

        $user = new User([
            'name' => 'Иван Петров',
            'email' => 'ivan@example.test',
        ]);

        $this->actingAs($user, 'api_landing')
            ->postJson('/api/v1/landing/support', [
                'subject' => 'Нужна помощь по доступу',
                'message' => 'Не получается открыть раздел документов.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(SupportRequestMail::class, function (SupportRequestMail $mail): bool {
            return $mail->hasTo('request@xn--1-xtbgmf.xn--p1ai')
                && ! $mail->hasTo('noreply@example.test');
        });
    }
}
